<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Storefront;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductExport\ProductExportCollection;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\Content\ProductExport\Storefront\AgenticFeedDiscoverySubscriber;
use Swag\AgenticCommerce\Content\ProductExport\Storefront\AgenticFeedLinkResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/** @internal */
#[CoversClass(AgenticFeedDiscoverySubscriber::class)]
final class AgenticFeedDiscoverySubscriberTest extends TestCase
{
    private const HREF = 'http://localhost:8000/store-api/product-export/SWAGKEY123/feed.xml';

    public function testSubscribesToKernelResponse(): void
    {
        static::assertArrayHasKey(KernelEvents::RESPONSE, AgenticFeedDiscoverySubscriber::getSubscribedEvents());
    }

    public function testInjectsLinkIntoHeadOnStorefrontHtmlResponse(): void
    {
        $response = $this->handle($this->storefrontRequest(), $this->htmlResponse());

        static::assertStringContainsString(
            '<link rel="alternate" type="application/rss+xml" title="Agentic product feed" href="'.self::HREF.'"></head>',
            (string) $response->getContent(),
        );
        static::assertFalse($response->headers->has('Content-Length'));
    }

    public function testSkipsSubRequest(): void
    {
        $response = $this->handle($this->storefrontRequest(), $this->htmlResponse(), HttpKernelInterface::SUB_REQUEST);

        static::assertStringNotContainsString('application/rss+xml', (string) $response->getContent());
    }

    public function testSkipsNonStorefrontScope(): void
    {
        $request = $this->storefrontRequest();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, ['store-api']);

        $response = $this->handle($request, $this->htmlResponse());

        static::assertStringNotContainsString('application/rss+xml', (string) $response->getContent());
    }

    public function testSkipsNonOkStatus(): void
    {
        $response = $this->handle($this->storefrontRequest(), $this->htmlResponse(Response::HTTP_NOT_FOUND));

        static::assertStringNotContainsString('application/rss+xml', (string) $response->getContent());
    }

    public function testSkipsNonHtmlResponse(): void
    {
        $response = new Response('{"ok":true}</head>', Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $response = $this->handle($this->storefrontRequest(), $response);

        static::assertStringNotContainsString('application/rss+xml', (string) $response->getContent());
    }

    public function testSkipsWhenNoHeadTag(): void
    {
        $response = new Response('<html><body>no head</body></html>', Response::HTTP_OK, ['Content-Type' => 'text/html']);
        $response = $this->handle($this->storefrontRequest(), $response);

        static::assertStringNotContainsString('application/rss+xml', (string) $response->getContent());
    }

    public function testDoesNotDuplicateWhenAlreadyPresent(): void
    {
        $existing = '<html><head><link href="'.self::HREF.'"></head><body></body></html>';
        $response = $this->handle($this->storefrontRequest(), new Response($existing, Response::HTTP_OK, ['Content-Type' => 'text/html']));

        static::assertSame(1, substr_count((string) $response->getContent(), self::HREF));
    }

    public function testSkipsWhenNoFeedConfigured(): void
    {
        $subscriber = new AgenticFeedDiscoverySubscriber(new AgenticFeedLinkResolver($this->repositoryReturning(null)));
        $event = $this->event($this->storefrontRequest(), $this->htmlResponse());

        $subscriber->injectFeedDiscoveryLink($event);

        static::assertStringNotContainsString('application/rss+xml', (string) $event->getResponse()->getContent());
    }

    private function handle(Request $request, Response $response, int $requestType = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $subscriber = new AgenticFeedDiscoverySubscriber(
            new AgenticFeedLinkResolver($this->repositoryReturning($this->export('SWAGKEY123', 'feed.xml'))),
        );
        $event = $this->event($request, $response, $requestType);
        $subscriber->injectFeedDiscoveryLink($event);

        return $event->getResponse();
    }

    private function event(Request $request, Response $response, int $requestType = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, $requestType, $response);
    }

    private function storefrontRequest(): Request
    {
        $request = Request::create('http://localhost:8000/');
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, [StorefrontRouteScope::ID]);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID, Uuid::randomHex());

        return $request;
    }

    private function htmlResponse(int $status = Response::HTTP_OK): Response
    {
        return new Response('<html><head><title>x</title></head><body></body></html>', $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function export(string $accessKey, string $fileName): ProductExportEntity
    {
        $export = new ProductExportEntity();
        $export->setUniqueIdentifier(Uuid::randomHex());
        $export->setAccessKey($accessKey);
        $export->setFileName($fileName);

        return $export;
    }

    /**
     * @return EntityRepository<ProductExportCollection>
     */
    private function repositoryReturning(?ProductExportEntity $export): EntityRepository
    {
        $collection = new ProductExportCollection($export !== null ? [$export] : []);
        $result = new EntitySearchResult('product_export', $collection->count(), $collection, null, new Criteria(), Context::createDefaultContext());

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }
}
