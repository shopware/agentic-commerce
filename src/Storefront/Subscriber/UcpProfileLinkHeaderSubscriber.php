<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Storefront\Subscriber;

use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** @internal */
#[Package('discovery')]
final class UcpProfileLinkHeaderSubscriber implements EventSubscriberInterface
{
    private const UCP_PROFILE_LINK = '<%s/.well-known/ucp>; rel="service-meta"';

    public function __construct(
        private readonly UcpConfigService $configService,
        private readonly SalesChannelDomainResolver $domainResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run after Shopware's CanonicalLinkListener so that the UCP link is appended instead of replaced.
            BeforeSendResponseEvent::class => ['onBeforeSendResponse', -100],
        ];
    }

    public function onBeforeSendResponse(BeforeSendResponseEvent $event): void
    {
        if (!$this->isHtmlResponse($event)) {
            return;
        }

        $resolvedDomain = $this->domainResolver->resolveByRequest($event->getRequest());
        if (
            null === $resolvedDomain
            || !$this->isUcpActiveForSalesChannel($resolvedDomain->salesChannelId)
        ) {
            return;
        }

        $domainPath = rtrim((string) parse_url($resolvedDomain->baseUrl, \PHP_URL_PATH), '/');
        $profileLink = \sprintf(self::UCP_PROFILE_LINK, $domainPath);
        $response = $event->getResponse();
        if ($response->headers->contains('Link', $profileLink)) {
            return;
        }

        $response->headers->set('Link', $profileLink, false);
    }

    private function isHtmlResponse(BeforeSendResponseEvent $event): bool
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        return 'html' === $request->getFormat($response->headers->get('Content-Type', ''));
    }

    private function isUcpActiveForSalesChannel(string $salesChannelId): bool
    {
        return $this->configService->getConfig($salesChannelId)->active;
    }
}
