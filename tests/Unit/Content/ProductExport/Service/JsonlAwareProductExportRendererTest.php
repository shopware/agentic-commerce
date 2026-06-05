<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Content\ProductExport\Service\ProductExportRendererInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\Generator;
use Swag\AgenticCommerce\Content\ProductExport\Service\JsonlAwareProductExportRenderer;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * @internal
 */
#[CoversClass(JsonlAwareProductExportRenderer::class)]
class JsonlAwareProductExportRendererTest extends TestCase
{
    public function testHeaderAndFooterAreDelegated(): void
    {
        $context = $this->createSalesChannelContext();
        $entity = $this->createJsonlEntity();

        $inner = $this->createMock(ProductExportRendererInterface::class);
        $inner->expects(static::once())->method('renderHeader')->with($entity, $context)->willReturn('HEADER');
        $inner->expects(static::once())->method('renderFooter')->with($entity, $context)->willReturn('FOOTER');

        $decorator = new JsonlAwareProductExportRenderer($inner);

        static::assertSame('HEADER', $decorator->renderHeader($entity, $context));
        static::assertSame('FOOTER', $decorator->renderFooter($entity, $context));
    }

    public function testRenderBodyDelegatesUnchangedForNonJsonlExport(): void
    {
        $context = $this->createSalesChannelContext();
        $entity = new ProductExportEntity();
        $entity->setFileFormat(ProductExportEntity::FILE_FORMAT_CSV);

        $inner = $this->createMock(ProductExportRendererInterface::class);
        $inner->expects(static::once())
            ->method('renderBody')
            ->with($entity, $context, [])
            ->willReturn("foo\n");

        $decorator = new JsonlAwareProductExportRenderer($inner);

        static::assertSame("foo\n", $decorator->renderBody($entity, $context, []));
    }

    public function testRenderBodyReturnsEmptyStringWhenInnerProducesOnlyWhitespace(): void
    {
        $context = $this->createSalesChannelContext();
        $entity = $this->createJsonlEntity();

        $inner = $this->createMock(ProductExportRendererInterface::class);
        $inner->expects(static::once())
            ->method('renderBody')
            ->willReturn("   \n  \t\n");

        $decorator = new JsonlAwareProductExportRenderer($inner);

        static::assertSame('', $decorator->renderBody($entity, $context, []));
    }

    public function testRenderBodyNormalizesValidJsonRowToSingleLine(): void
    {
        $context = $this->createSalesChannelContext();
        $entity = $this->createJsonlEntity();

        $inner = $this->createMock(ProductExportRendererInterface::class);
        $inner->expects(static::once())
            ->method('renderBody')
            ->willReturn("  {\n  \"item_id\": \"sku-1\",\n  \"price\": \"10.99 EUR\"\n}  \n");

        $decorator = new JsonlAwareProductExportRenderer($inner);

        static::assertSame(
            '{"item_id":"sku-1","price":"10.99 EUR"}'.\PHP_EOL,
            $decorator->renderBody($entity, $context, [])
        );
    }

    public function testRenderBodyPreservesUnescapedSlashes(): void
    {
        $context = $this->createSalesChannelContext();
        $entity = $this->createJsonlEntity();

        $inner = $this->createMock(ProductExportRendererInterface::class);
        $inner->expects(static::once())
            ->method('renderBody')
            ->willReturn('{"url":"https://example.com/item"}');

        $decorator = new JsonlAwareProductExportRenderer($inner);

        static::assertSame(
            '{"url":"https://example.com/item"}'.\PHP_EOL,
            $decorator->renderBody($entity, $context, [])
        );
    }

    public function testRenderBodyEncodesUnescapedSpacesInUrlValues(): void
    {
        $context = $this->createSalesChannelContext();
        $entity = $this->createJsonlEntity();

        $inner = $this->createMock(ProductExportRendererInterface::class);
        $inner->expects(static::once())
            ->method('renderBody')
            ->willReturn('{"image_url":"https://example.com/media/Nice Burger.jpg","title":"Nice Burger","extra":{"alt_url":"http://example.com/foo bar"}}');

        $decorator = new JsonlAwareProductExportRenderer($inner);

        static::assertSame(
            '{"image_url":"https://example.com/media/Nice%20Burger.jpg","title":"Nice Burger","extra":{"alt_url":"http://example.com/foo%20bar"}}'.\PHP_EOL,
            $decorator->renderBody($entity, $context, [])
        );
    }

    public function testRenderBodyKeepsTrimmedRawValueWhenJsonIsInvalid(): void
    {
        $context = $this->createSalesChannelContext();
        $entity = $this->createJsonlEntity();

        $inner = $this->createMock(ProductExportRendererInterface::class);
        $inner->expects(static::once())
            ->method('renderBody')
            ->willReturn(" {malformed} \n");

        $decorator = new JsonlAwareProductExportRenderer($inner);

        static::assertSame(
            '{malformed}'.\PHP_EOL,
            $decorator->renderBody($entity, $context, [])
        );
    }

    public function testRenderBodyKeepsTrimmedRawValueWhenJsonDoesNotDecodeToObject(): void
    {
        $context = $this->createSalesChannelContext();
        $entity = $this->createJsonlEntity();

        $inner = $this->createMock(ProductExportRendererInterface::class);
        $inner->expects(static::once())
            ->method('renderBody')
            ->willReturn('"just-a-string"');

        $decorator = new JsonlAwareProductExportRenderer($inner);

        static::assertSame(
            '"just-a-string"'.\PHP_EOL,
            $decorator->renderBody($entity, $context, [])
        );
    }

    private function createJsonlEntity(): ProductExportEntity
    {
        $entity = new ProductExportEntity();
        $entity->setFileFormat(SwagAgenticCommerce::FILE_FORMAT_JSONL);

        return $entity;
    }

    private function createSalesChannelContext(): \Shopware\Core\System\SalesChannel\SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('sales-channel-id');

        return Generator::generateSalesChannelContext(
            baseContext: Context::createDefaultContext(),
            salesChannel: $salesChannel
        );
    }
}
