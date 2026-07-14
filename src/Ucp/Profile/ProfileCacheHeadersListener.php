<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Profile;

use Shopware\Core\Framework\Adapter\Cache\Http\Event\BeforeCacheControlEvent;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces the UCP discovery caching policy on /.well-known/ucp responses:
 * the spec requires `Cache-Control: public` with a max-age of at least 60
 * seconds and forbids private/no-store/no-cache. Shopware's storefront cache
 * machinery stamps session-bound responses with `no-cache, private`, so this
 * listener runs after it (negative priority) and restores the mandated policy.
 */
#[Package('framework')]
final class ProfileCacheHeadersListener implements EventSubscriberInterface
{
    private const PROFILE_PATH = '/.well-known/ucp';

    private const MAX_AGE_SECONDS = 60;

    public static function getSubscribedEvents(): array
    {
        // After Shopware's CacheResponseSubscriber and Symfony's session
        // listener adjustments. BeforeCacheControlEvent additionally exempts
        // the profile from Shopware's client-facing no-cache rewrite in
        // CacheControlListener (BeforeSendResponseEvent).
        return [
            KernelEvents::RESPONSE => ['onResponse', -2000],
            BeforeCacheControlEvent::class => 'onBeforeCacheControl',
        ];
    }

    public function onBeforeCacheControl(BeforeCacheControlEvent $event): void
    {
        if (self::PROFILE_PATH === $event->request->getPathInfo()) {
            $event->skipCacheControl();
        }
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

        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE_SECONDS);
        $response->headers->removeCacheControlDirective('no-cache');
        $response->headers->removeCacheControlDirective('no-store');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, '1');
    }
}
