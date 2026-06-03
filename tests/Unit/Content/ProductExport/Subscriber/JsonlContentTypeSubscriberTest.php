<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductExport\Event\ProductExportContentTypeEvent;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Swag\AgenticCommerce\Content\ProductExport\Subscriber\JsonlContentTypeSubscriber;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * @internal
 */
#[CoversClass(JsonlContentTypeSubscriber::class)]
class JsonlContentTypeSubscriberTest extends TestCase
{
    public function testSubscribesToContentTypeEvent(): void
    {
        static::assertSame(
            [ProductExportContentTypeEvent::class => 'onContentType'],
            JsonlContentTypeSubscriber::getSubscribedEvents()
        );
    }

    public function testSetsNdjsonContentTypeForJsonlExports(): void
    {
        $event = new ProductExportContentTypeEvent(SwagAgenticCommerce::FILE_FORMAT_JSONL, 'text/plain');

        (new JsonlContentTypeSubscriber())->onContentType($event);

        static::assertSame('application/x-ndjson', $event->getContentType());
    }

    public function testLeavesContentTypeUntouchedForOtherFormats(): void
    {
        $event = new ProductExportContentTypeEvent(ProductExportEntity::FILE_FORMAT_CSV, 'text/csv');

        (new JsonlContentTypeSubscriber())->onContentType($event);

        static::assertSame('text/csv', $event->getContentType());
    }
}
