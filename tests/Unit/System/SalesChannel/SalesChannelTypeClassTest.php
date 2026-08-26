<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\System\SalesChannel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Swag\AgenticCommerce\SwagAgenticCommerce;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClass;

/** @internal */
#[CoversClass(SalesChannelTypeClass::class)]
final class SalesChannelTypeClassTest extends TestCase
{
    /**
     * @return iterable<string, array{string, SalesChannelTypeClass}>
     */
    public static function typeIdProvider(): iterable
    {
        yield 'storefront' => [Defaults::SALES_CHANNEL_TYPE_STOREFRONT, SalesChannelTypeClass::Storefront];
        yield 'headless api' => [Defaults::SALES_CHANNEL_TYPE_API, SalesChannelTypeClass::Headless];
        yield 'product comparison' => [Defaults::SALES_CHANNEL_TYPE_PRODUCT_COMPARISON, SalesChannelTypeClass::ProductComparison];
        yield 'agentic commerce' => [SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE, SalesChannelTypeClass::AgenticCommerce];
        yield 'a third-party type nobody registered here' => ['9ce0868f406d47d98cfe4b281e62f098', SalesChannelTypeClass::Other];
        yield 'no type at all' => ['', SalesChannelTypeClass::Other];
    }

    #[DataProvider('typeIdProvider')]
    public function testItClassifiesEveryTypeId(string $typeId, SalesChannelTypeClass $expected): void
    {
        static::assertSame($expected, SalesChannelTypeClass::forTypeId($typeId));
    }

    public function testOnlyStorefrontAndHeadlessAreTransactional(): void
    {
        static::assertTrue(SalesChannelTypeClass::Storefront->isTransactional());
        static::assertTrue(SalesChannelTypeClass::Headless->isTransactional());
        static::assertFalse(SalesChannelTypeClass::ProductComparison->isTransactional());
        static::assertFalse(SalesChannelTypeClass::AgenticCommerce->isTransactional());
        static::assertFalse(SalesChannelTypeClass::Other->isTransactional());
    }

    public function testOnlyTheFeedTypesAreProductExportChannels(): void
    {
        static::assertTrue(SalesChannelTypeClass::ProductComparison->isProductExport());
        static::assertTrue(SalesChannelTypeClass::AgenticCommerce->isProductExport());
        static::assertFalse(SalesChannelTypeClass::Storefront->isProductExport());
        static::assertFalse(SalesChannelTypeClass::Headless->isProductExport());
        static::assertFalse(SalesChannelTypeClass::Other->isProductExport());
    }

    public function testTheTwoGroupsAreDisjointAndLeaveOnlyTheNamedResidue(): void
    {
        $unclaimed = [];

        foreach (SalesChannelTypeClass::cases() as $class) {
            static::assertFalse(
                $class->isTransactional() && $class->isProductExport(),
                \sprintf('%s must not belong to both groups.', $class->name),
            );

            if (!$class->isTransactional() && !$class->isProductExport()) {
                $unclaimed[] = $class;
            }
        }

        static::assertSame([SalesChannelTypeClass::Other], $unclaimed);
    }

    public function testTheTransactionalTypeIdsMatchTheTransactionalCases(): void
    {
        $fromIds = array_map(
            static fn (string $typeId): SalesChannelTypeClass => SalesChannelTypeClass::forTypeId($typeId),
            SalesChannelTypeClass::transactionalTypeIds(),
        );

        static::assertSame([SalesChannelTypeClass::Storefront, SalesChannelTypeClass::Headless], $fromIds);
    }
}
