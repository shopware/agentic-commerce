<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\System\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Swag\AgenticCommerce\SwagAgenticCommerce;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;

/** @internal */
#[CoversClass(SalesChannelTypeClassification::class)]
final class SalesChannelTypeClassificationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, SalesChannelTypeClassification}>
     */
    public static function typeIdProvider(): iterable
    {
        yield 'storefront' => [Defaults::SALES_CHANNEL_TYPE_STOREFRONT, SalesChannelTypeClassification::Storefront];
        yield 'headless api' => [Defaults::SALES_CHANNEL_TYPE_API, SalesChannelTypeClassification::Headless];
        yield 'product comparison' => [Defaults::SALES_CHANNEL_TYPE_PRODUCT_COMPARISON, SalesChannelTypeClassification::ProductComparison];
        yield 'agentic commerce' => [SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE, SalesChannelTypeClassification::AgenticCommerce];
        yield 'a third-party type nobody registered here' => ['9ce0868f406d47d98cfe4b281e62f098', SalesChannelTypeClassification::Other];
        yield 'no type at all' => ['', SalesChannelTypeClassification::Other];
    }

    #[DataProvider('typeIdProvider')]
    public function testItClassifiesEveryTypeId(string $typeId, SalesChannelTypeClassification $expected): void
    {
        static::assertSame($expected, SalesChannelTypeClassification::forTypeId($typeId));
    }

    public function testOnlyStorefrontAndHeadlessAreTransactional(): void
    {
        static::assertTrue(SalesChannelTypeClassification::Storefront->isTransactional());
        static::assertTrue(SalesChannelTypeClassification::Headless->isTransactional());
        static::assertFalse(SalesChannelTypeClassification::ProductComparison->isTransactional());
        static::assertFalse(SalesChannelTypeClassification::AgenticCommerce->isTransactional());
        static::assertFalse(SalesChannelTypeClassification::Other->isTransactional());
    }

    public function testOnlyTheFeedTypesAreProductExportChannels(): void
    {
        static::assertTrue(SalesChannelTypeClassification::ProductComparison->isProductExport());
        static::assertTrue(SalesChannelTypeClassification::AgenticCommerce->isProductExport());
        static::assertFalse(SalesChannelTypeClassification::Storefront->isProductExport());
        static::assertFalse(SalesChannelTypeClassification::Headless->isProductExport());
        static::assertFalse(SalesChannelTypeClassification::Other->isProductExport());
    }

    public function testTheTwoGroupsAreDisjointAndLeaveOnlyTheNamedResidue(): void
    {
        $unclaimed = [];

        foreach (SalesChannelTypeClassification::cases() as $class) {
            static::assertFalse(
                $class->isTransactional() && $class->isProductExport(),
                \sprintf('%s must not belong to both groups.', $class->name),
            );

            if (!$class->isTransactional() && !$class->isProductExport()) {
                $unclaimed[] = $class;
            }
        }

        static::assertSame([SalesChannelTypeClassification::Other], $unclaimed);
    }
}
