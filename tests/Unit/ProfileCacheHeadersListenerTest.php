<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Swag\AgenticCommerce\Ucp\Profile\ProfileCacheHeadersListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** @internal */
final class ProfileCacheHeadersListenerTest extends TestCase
{
    #[Test]
    public function itSubscribesToTheResponseAndBeforeSendEvents(): void
    {
        $events = ProfileCacheHeadersListener::getSubscribedEvents();

        static::assertArrayHasKey(BeforeSendResponseEvent::class, $events);
        static::assertSame('onBeforeSendResponse', $events[BeforeSendResponseEvent::class][0]);
        // Must run after Shopware's CacheControlListener (priority 0) so it has the final word.
        static::assertLessThan(0, $events[BeforeSendResponseEvent::class][1]);
    }

    #[Test]
    public function itRestoresTheDiscoveryCachePolicyOnResponse(): void
    {
        $response = new Response();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');

        $this->listener()->onResponse($this->responseEvent('/.well-known/ucp', $response));

        static::assertTrue($response->headers->hasCacheControlDirective('public'));
        static::assertSame('60', $response->headers->getCacheControlDirective('max-age'));
        static::assertFalse($response->headers->hasCacheControlDirective('no-cache'));
        static::assertFalse($response->headers->hasCacheControlDirective('no-store'));
        static::assertFalse($response->headers->hasCacheControlDirective('private'));
        static::assertSame('1', $response->headers->get(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
    }

    /**
     * Reproduces the 6.5/6.6 (and no-reverse-proxy 6.7) path: after onResponse has
     * set the policy, Shopware's CacheControlListener rewrites the response to
     * `private, no-cache`. onBeforeSendResponse must restore the mandated policy.
     */
    #[Test]
    public function itRestoresThePolicyAfterTheCoreCacheControlRewrite(): void
    {
        $listener = $this->listener();
        $response = new Response();

        $listener->onResponse($this->responseEvent('/.well-known/ucp', $response));

        // Simulate CacheControlListener::__invoke (no reverse proxy configured).
        $response->headers->remove('cache-control');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');

        $listener->onBeforeSendResponse($this->beforeSendEvent('/.well-known/ucp', $response));

        static::assertTrue($response->headers->hasCacheControlDirective('public'));
        static::assertSame('60', $response->headers->getCacheControlDirective('max-age'));
        static::assertFalse($response->headers->hasCacheControlDirective('no-cache'));
        static::assertFalse($response->headers->hasCacheControlDirective('private'));
    }

    #[Test]
    public function itIgnoresUnrelatedPaths(): void
    {
        $response = new Response();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');

        $this->listener()->onBeforeSendResponse($this->beforeSendEvent('/some/other/path', $response));

        static::assertTrue($response->headers->hasCacheControlDirective('private'));
        static::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    #[Test]
    public function itLeavesErrorResponsesUntouched(): void
    {
        $response = new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        $response->setPrivate();

        $this->listener()->onBeforeSendResponse($this->beforeSendEvent('/.well-known/ucp', $response));

        static::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    private function listener(): ProfileCacheHeadersListener
    {
        return new ProfileCacheHeadersListener();
    }

    private function responseEvent(string $path, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }

    private function beforeSendEvent(string $path, Response $response): BeforeSendResponseEvent
    {
        return new BeforeSendResponseEvent(Request::create($path), $response);
    }
}
