<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItemDownload\OrderLineItemDownloadCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItemDownload\OrderLineItemDownloadEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Swag\AgenticCommerce\Ucp\Fulfillment\CheckoutDownloadAugmenter;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\OrderConfirmation;
use Ucp\Sdk\Model\RequestContext;

/**
 * @internal
 */
#[CoversClass(CheckoutDownloadAugmenter::class)]
final class CheckoutDownloadAugmenterTest extends TestCase
{
    private const ORDER_ID = '019f0000000000000000000000000abc';
    private const DOWNLOAD_ID = '00000000000000000000000000000d01';

    #[Test]
    public function testAddsDownloadLinkWithDeepLinkCodeFromHostHeader(): void
    {
        $augmenter = new CheckoutDownloadAugmenter($this->orderRepo($this->orderWithDownload()));

        $result = $augmenter->augment(
            $this->checkoutWithOrder(),
            new RequestContext(host: '127.0.0.1', headers: ['host' => 'localhost:8000']),
        );

        $downloads = $result->extra['downloads'] ?? null;
        self::assertIsArray($downloads);
        self::assertCount(1, $downloads);
        self::assertSame(
            'http://localhost:8000/store-api/agentic-commerce/order/'.self::ORDER_ID.'/download/'.self::DOWNLOAD_ID.'?deepLinkCode=DLC123',
            $downloads[0]['url'],
        );
        self::assertSame('ultimate-ai-prompt-collection.zip', $downloads[0]['filename']);
        self::assertTrue($downloads[0]['available']);
    }

    #[Test]
    public function testNoDownloadsLeavesExtraUntouched(): void
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setDeepLinkCode('DLC123');
        $order->setLineItems(new OrderLineItemCollection());

        $augmenter = new CheckoutDownloadAugmenter($this->orderRepo($order));
        $result = $augmenter->augment(
            $this->checkoutWithOrder(),
            new RequestContext(host: '127.0.0.1', headers: ['host' => 'localhost:8000']),
        );

        self::assertArrayNotHasKey('downloads', $result->extra);
    }

    private function checkoutWithOrder(): Checkout
    {
        return new Checkout(
            'co',
            CheckoutStatus::Completed,
            'USD',
            [],
            [],
            [],
            [],
            null,
            null,
            null,
            new OrderConfirmation(self::ORDER_ID, null),
            [],
        );
    }

    private function orderRepo(OrderEntity $order): EntityRepository
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturnCallback(
            static fn ($criteria, $context): EntitySearchResult => new EntitySearchResult(
                'order',
                1,
                new OrderCollection([$order]),
                null,
                $criteria,
                $context,
            ),
        );

        return $repo;
    }

    private function orderWithDownload(): OrderEntity
    {
        $media = new MediaEntity();
        $media->setId('00000000000000000000000000000e01');
        $media->setFileName('ultimate-ai-prompt-collection');
        $media->setFileExtension('zip');

        $download = new OrderLineItemDownloadEntity();
        $download->setId(self::DOWNLOAD_ID);
        $download->setAccessGranted(true);
        $download->setMedia($media);

        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('00000000000000000000000000000c01');
        $lineItem->setDownloads(new OrderLineItemDownloadCollection([$download]));

        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setDeepLinkCode('DLC123');
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        return $order;
    }
}
