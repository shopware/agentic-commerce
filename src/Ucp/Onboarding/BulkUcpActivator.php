<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Config\Validation\UcpConfigValidator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelView;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;

/**
 * Applies one UCP exposure payload to several sales channels.
 *
 * The onboarding dialog's only new backend behaviour. Everything it does per
 * channel already exists: {@see UcpConfigService::saveConfig()} writes the
 * config with the same merge semantics as `ucp:config:set`, enables the agentic
 * files and provisions a signing key, and {@see UcpConfigValidator} produces the
 * same readiness findings `ucp:config:validate` renders. This composes them and
 * reports an outcome per channel.
 *
 * Eligibility is not re-implemented here: a non-transactional channel is
 * refused by `saveConfig()` itself, and that refusal becomes this channel's
 * outcome. One refused channel never aborts the batch.
 *
 * `profileDomain` is deliberately absent from the payload. The merge preserves
 * whatever is stored (null for a fresh channel), so each channel resolves its
 * profile from its own domain and a domain a merchant pinned earlier survives.
 *
 * @internal
 */
#[Package('framework')]
final class BulkUcpActivator
{
    public function __construct(
        private readonly SalesChannelViewProvider $salesChannelViewProvider,
        private readonly UcpConfigService $configService,
        private readonly ChannelFindingsResolver $findingsResolver,
    ) {
    }

    /**
     * @param list<string> $salesChannelIds
     * @param list<string> $enabledCapabilities
     * @param list<string> $enabledTransports
     *
     * @return list<ChannelActivationOutcome>
     */
    public function activate(
        array $salesChannelIds,
        array $enabledCapabilities,
        array $enabledTransports,
        Context $context,
    ): array {
        if ([] === $salesChannelIds) {
            return [];
        }

        $views = $this->viewsById($context);
        $stored = $this->configService->getConfigs($salesChannelIds);

        $payload = [
            'active' => true,
            'enabledCapabilities' => $enabledCapabilities,
            'enabledTransports' => $enabledTransports,
        ];

        $outcomes = [];
        foreach ($salesChannelIds as $salesChannelId) {
            $outcomes[] = $this->activateOne(
                $salesChannelId,
                $views[$salesChannelId] ?? null,
                $stored[$salesChannelId] ?? null,
                $payload,
            );
        }

        return $outcomes;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function activateOne(
        string $salesChannelId,
        ?SalesChannelView $view,
        ?UcpConfig $stored,
        array $payload,
    ): ChannelActivationOutcome {
        $name = $view?->name;

        if (null !== $stored && $this->isAlreadySatisfied($stored, $payload)) {
            return ChannelActivationOutcome::skipped($salesChannelId, $name, ChannelActivationOutcome::REASON_ALREADY_ACTIVE);
        }

        try {
            $config = $this->configService->saveConfig($payload, $salesChannelId);
        } catch (UcpConfigException $exception) {
            return ChannelActivationOutcome::failed($salesChannelId, $name, $exception->getErrorCode(), $exception->getMessage());
        }

        return ChannelActivationOutcome::enabled($salesChannelId, $name, $this->findingsResolver->resolve($salesChannelId, $name, $config, $view));
    }

    /**
     * Already active with the requested capabilities and transports, so writing
     * would be a no-op. Reported rather than silently repeated, so the dialog
     * can say the channel was already on instead of claiming it turned it on.
     *
     * @param array<string, mixed> $payload
     */
    private function isAlreadySatisfied(UcpConfig $stored, array $payload): bool
    {
        if (!$stored->active) {
            return false;
        }

        return $this->sameSet($stored->enabledCapabilities, $payload['enabledCapabilities'])
            && $this->sameSet($stored->enabledTransports, $payload['enabledTransports']);
    }

    /**
     * @param list<string> $stored
     */
    private function sameSet(array $stored, mixed $requested): bool
    {
        if (!\is_array($requested)) {
            return false;
        }

        $left = array_values(array_unique($stored));
        /** @var list<string> $right */
        $right = array_values(array_unique($requested));
        sort($left);
        sort($right);

        return $left === $right;
    }

    /**
     * @return array<string, SalesChannelView>
     */
    private function viewsById(Context $context): array
    {
        $views = [];
        foreach ($this->salesChannelViewProvider->all($context) as $view) {
            $views[$view->id] = $view;
        }

        return $views;
    }
}
