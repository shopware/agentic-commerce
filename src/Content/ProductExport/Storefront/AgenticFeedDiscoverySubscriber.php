<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Storefront;

use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Advertises the agentic product feed in the storefront <head> so agent crawlers
 * discover it. The plugin ships no storefront theme integration, so the link is
 * injected into the rendered response instead of via a template override.
 *
 * @internal
 */
final class AgenticFeedDiscoverySubscriber implements EventSubscriberInterface
{
    private const FEED_TITLE = 'Agentic product feed';

    public function __construct(
        private readonly AgenticFeedLinkResolver $feedLinkResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Late priority: run after the storefront has produced the final HTML.
        return [
            KernelEvents::RESPONSE => ['injectFeedDiscoveryLink', -1024],
        ];
    }

    public function injectFeedDiscoveryLink(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $scopes = $request->attributes->get(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, []);
        if (!\is_array($scopes) || !\in_array(StorefrontRouteScope::ID, $scopes, true)) {
            return;
        }

        $response = $event->getResponse();
        if (Response::HTTP_OK !== $response->getStatusCode()) {
            return;
        }

        if (!str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return;
        }

        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        if (!\is_string($salesChannelId) || '' === $salesChannelId) {
            return;
        }

        $feedPath = $this->feedLinkResolver->resolveFeedPath($salesChannelId);
        if (null === $feedPath) {
            return;
        }

        $content = $response->getContent();
        if (!\is_string($content) || '' === $content) {
            return;
        }

        $headEnd = stripos($content, '</head>');
        if (false === $headEnd) {
            return;
        }

        $href = $request->getSchemeAndHttpHost().$feedPath;
        if (str_contains($content, $href)) {
            return;
        }

        $link = \sprintf(
            '<link rel="alternate" type="application/rss+xml" title="%s" href="%s">',
            htmlspecialchars(self::FEED_TITLE, \ENT_QUOTES),
            htmlspecialchars($href, \ENT_QUOTES),
        );

        $response->setContent(substr($content, 0, $headEnd).$link.substr($content, $headEnd));
        // Content length changed; let the kernel recompute it.
        $response->headers->remove('Content-Length');
    }
}
