<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Profile;

use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces the UCP discovery caching policy on /.well-known/ucp responses:
 * the spec requires `Cache-Control: public` with a max-age of at least 60
 * seconds and forbids private/no-store/no-cache.
 *
 * Two of Shopware's response stages would otherwise strip that policy, so the
 * listener re-applies it after each of them:
 *
 *  - On KernelEvents::RESPONSE, Shopware's CacheResponseSubscriber and Symfony's
 *    AbstractSessionListener stamp session-bound responses with `no-cache, private`.
 *    onResponse() runs after them (negative priority) and marks the response with
 *    NO_AUTO_CACHE_CONTROL_HEADER so the session listener leaves it alone.
 *  - On BeforeSendResponseEvent, Shopware's CacheControlListener rewrites every
 *    client-facing response to `private, no-cache` when no reverse proxy is
 *    configured. This happens on all supported versions (6.5/6.6/6.7); only 6.7
 *    offers a per-request opt-out (BeforeCacheControlEvent), so relying on it
 *    would leave 6.5/6.6 unprotected. Instead onBeforeSendResponse() runs after
 *    CacheControlListener (priority 0) and restores the mandated policy on every
 *    lane. BeforeSendResponseEvent is the last hook before the response is sent,
 *    so this is the authoritative final state.
 */
#[Package('framework')]
final class ProfileCacheHeadersListener implements EventSubscriberInterface
{
    private const PROFILE_PATH = '/.well-known/ucp';

    private const MAX_AGE_SECONDS = 60;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -2000],
            // After Shopware's CacheControlListener (BeforeSendResponseEvent,
            // priority 0), which would otherwise downgrade the response to
            // `private, no-cache` on every lane when no reverse proxy is used.
            BeforeSendResponseEvent::class => ['onBeforeSendResponse', -1000],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || self::PROFILE_PATH !== $event->getRequest()->getPathInfo()) {
            return;
        }

        $response = $event->getResponse();
        if (!$response->isSuccessful()) {
            return;
        }

        $this->applyDiscoveryCachePolicy($response);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, '1');
    }

    public function onBeforeSendResponse(BeforeSendResponseEvent $event): void
    {
        if (self::PROFILE_PATH !== $event->getRequest()->getPathInfo()) {
            return;
        }

        $response = $event->getResponse();
        if (!$response->isSuccessful()) {
            return;
        }

        $this->applyDiscoveryCachePolicy($response);
    }

    private function applyDiscoveryCachePolicy(Response $response): void
    {
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE_SECONDS);
        $response->headers->removeCacheControlDirective('no-cache');
        $response->headers->removeCacheControlDirective('no-store');
    }
}
