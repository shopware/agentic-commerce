<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Admin\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Onboarding\AgenticFeedChannelCreator;
use Swag\AgenticCommerce\Ucp\Onboarding\BulkUcpActivator;
use Swag\AgenticCommerce\Ucp\Onboarding\ChannelActivationOutcome;
use Swag\AgenticCommerce\Ucp\Onboarding\FeedChannelOutcome;
use Swag\AgenticCommerce\Ucp\Onboarding\FeedProvider;
use Swag\AgenticCommerce\Ucp\Onboarding\ShopReadinessProvider;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Backs the Agentic Commerce onboarding surface.
 *
 * `readiness` is the settings page in one read: status, the three setup steps and
 * every offerable channel with its outstanding findings. `bulk-enable` is what the
 * prepare flow posts and `feed-channels` what the connect flow posts; both respond
 * 200 with per-item outcomes even when some failed, because partial success is the
 * normal case for a bulk operation.
 *
 * @internal
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
#[Package('framework')]
final class UcpOnboardingController
{
    public function __construct(
        private readonly BulkUcpActivator $activator,
        private readonly ShopReadinessProvider $readinessProvider,
        private readonly AgenticFeedChannelCreator $feedChannelCreator,
    ) {
    }

    #[Route(
        path: '/api/_admin/ucp/onboarding/readiness',
        name: 'api.action.swag_agentic_commerce.ucp.onboarding.readiness',
        methods: ['GET'],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.viewer']],
    )]
    public function readiness(Context $context): JsonResponse
    {
        return new JsonResponse(['data' => $this->readinessProvider->readiness($context)]);
    }

    #[Route(
        path: '/api/_admin/ucp/onboarding/bulk-enable',
        name: 'api.action.swag_agentic_commerce.ucp.onboarding.bulk_enable',
        methods: ['POST'],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.editor']],
    )]
    public function bulkEnable(Request $request, Context $context): JsonResponse
    {
        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            throw UcpConfigException::invalidJsonPayload();
        }

        [$capabilities, $transports] = $this->exposure($payload);

        $outcomes = $this->activator->activate(
            $this->salesChannelIds($payload),
            $capabilities,
            $transports,
            $context,
        );

        return new JsonResponse([
            'data' => [
                'results' => $outcomes,
                'enabled' => $this->count($outcomes, static fn (ChannelActivationOutcome $o): bool => $o->isEnabled()),
                'skipped' => $this->count($outcomes, static fn (ChannelActivationOutcome $o): bool => $o->isSkipped()),
                'failed' => $this->count($outcomes, static fn (ChannelActivationOutcome $o): bool => $o->isFailed()),
            ],
        ]);
    }

    #[Route(
        path: '/api/_admin/ucp/onboarding/feed-channels',
        name: 'api.action.swag_agentic_commerce.ucp.onboarding.feed_channels',
        methods: ['POST'],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['ucp.editor']],
    )]
    public function feedChannels(Request $request, Context $context): JsonResponse
    {
        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            throw UcpConfigException::invalidJsonPayload();
        }

        $outcomes = $this->feedChannelCreator->create($this->feedRequests($payload), $context);

        return new JsonResponse([
            'data' => [
                'results' => $outcomes,
                'created' => \count(array_filter($outcomes, static fn (FeedChannelOutcome $o): bool => $o->isCreated())),
                'skipped' => \count(array_filter($outcomes, static fn (FeedChannelOutcome $o): bool => $o->isSkipped())),
                'failed' => \count(array_filter($outcomes, static fn (FeedChannelOutcome $o): bool => $o->isFailed())),
            ],
        ]);
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<array{storefrontSalesChannelId: string, provider: FeedProvider}>
     */
    private function feedRequests(array $payload): array
    {
        $feeds = $payload['feeds'] ?? null;
        if (!\is_array($feeds) || !array_is_list($feeds) || [] === $feeds) {
            throw UcpConfigException::invalidValue('$.feeds', 'must be a non-empty list');
        }

        $requests = [];
        foreach ($feeds as $index => $feed) {
            $path = \sprintf('$.feeds[%d]', $index);
            if (!\is_array($feed)) {
                throw UcpConfigException::invalidValue($path, 'must be an object');
            }

            $storefrontId = $feed['storefrontSalesChannelId'] ?? null;
            if (!\is_string($storefrontId) || '' === trim($storefrontId)) {
                throw UcpConfigException::invalidValue($path.'.storefrontSalesChannelId', 'must be a non-empty string');
            }

            $provider = \is_string($feed['provider'] ?? null) ? FeedProvider::tryFrom($feed['provider']) : null;
            if (null === $provider) {
                throw UcpConfigException::invalidValue($path.'.provider', \sprintf('must be one of "%s"', implode('", "', array_column(FeedProvider::cases(), 'value'))));
            }

            $requests[trim($storefrontId).'|'.$provider->value] = ['storefrontSalesChannelId' => trim($storefrontId), 'provider' => $provider];
        }

        return array_values($requests);
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<string>
     */
    private function salesChannelIds(array $payload): array
    {
        $ids = $payload['salesChannelIds'] ?? null;
        if (!\is_array($ids) || !array_is_list($ids)) {
            throw UcpConfigException::invalidValue('$.salesChannelIds', 'must be a list');
        }

        $normalized = [];
        foreach ($ids as $index => $id) {
            if (!\is_string($id) || '' === trim($id)) {
                throw UcpConfigException::invalidValue(\sprintf('$.salesChannelIds[%d]', $index), 'must be a non-empty string');
            }

            $normalized[] = trim($id);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Validates the exposure subset by building the very config object the save
     * path builds, so the rules and their messages live in exactly one place
     * ({@see UcpConfig::fromArray}). Done up front rather than per channel: a
     * malformed capability is a bad request, not a per-channel outcome.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function exposure(array $payload): array
    {
        $config = UcpConfig::fromArray(array_filter(
            [
                'enabledCapabilities' => $payload['enabledCapabilities'] ?? null,
                'enabledTransports' => $payload['enabledTransports'] ?? null,
            ],
            static fn (mixed $value): bool => null !== $value,
        ));

        return [$config->enabledCapabilities, $config->enabledTransports];
    }

    /**
     * @param list<ChannelActivationOutcome>           $outcomes
     * @param \Closure(ChannelActivationOutcome): bool $matches
     */
    private function count(array $outcomes, \Closure $matches): int
    {
        return \count(array_filter($outcomes, $matches));
    }
}
