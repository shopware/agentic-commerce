<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Seeder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Rule\LineItemRule;
use Shopware\Core\Checkout\Promotion\PromotionCollection;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\Seeder\PromotionSeeder;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * @internal
 */
#[CoversClass(PromotionSeeder::class)]
class PromotionSeederTest extends TestCase
{
    private const SALES_CHANNEL_IDS = ['0191aaaaaaaa7000aaaaaaaaaaaaaaaa', '0191bbbbbbbb7000bbbbbbbbbbbbbbbb'];

    public function testCodePromotionsAndTheRuleLimitedAutomaticPromotion(): void
    {
        /** @var StaticEntityRepository<PromotionCollection> $promotionRepository */
        $promotionRepository = new StaticEntityRepository([]);
        /** @var StaticEntityRepository<RuleCollection> $ruleRepository */
        $ruleRepository = new StaticEntityRepository([]);

        (new PromotionSeeder($promotionRepository, $ruleRepository))->create(self::SALES_CHANNEL_IDS, Context::createDefaultContext());

        $promotionById = array_column($promotionRepository->creates[0], null, 'id');

        $percentage = $promotionById[TestDataIds::id(PromotionSeeder::PERCENTAGE_PROMOTION)];
        static::assertTrue($percentage['useCodes']);
        static::assertSame(PromotionSeeder::PERCENTAGE_CODE, $percentage['code']);
        static::assertSame(['cart', 'percentage', 10.0], [$percentage['discounts'][0]['scope'], $percentage['discounts'][0]['type'], $percentage['discounts'][0]['value']]);

        $freeShipping = $promotionById[TestDataIds::id(PromotionSeeder::FREE_SHIPPING_PROMOTION)];
        static::assertSame(PromotionSeeder::FREE_SHIPPING_CODE, $freeShipping['code']);
        static::assertSame(['delivery', 'percentage', 100.0], [$freeShipping['discounts'][0]['scope'], $freeShipping['discounts'][0]['type'], $freeShipping['discounts'][0]['value']]);

        $ruleId = TestDataIds::id(PromotionSeeder::RULE_CART_HAS_TIER_PRODUCT);
        $automatic = $promotionById[TestDataIds::id(PromotionSeeder::AUTOMATIC_PROMOTION)];
        static::assertFalse($automatic['useCodes']);
        static::assertArrayNotHasKey('code', $automatic);
        static::assertSame([['id' => $ruleId]], $automatic['cartRules']);

        $condition = $ruleRepository->upserts[0][0]['conditions'][0]['children'][0]['children'][0];
        static::assertSame(LineItemRule::RULE_NAME, $condition['type']);
        static::assertSame([TestDataIds::id(ProductSeeder::TIER_PRICES)], $condition['value']['identifiers']);

        foreach ($promotionById as $promotion) {
            static::assertTrue($promotion['customFields'][TestDataIds::MARKER]);
            static::assertSame(self::SALES_CHANNEL_IDS, array_column($promotion['salesChannels'], 'salesChannelId'));
        }
    }

    public function testExistsChecksThePercentageCodePromotion(): void
    {
        /** @var StaticEntityRepository<PromotionCollection> $promotionRepository */
        $promotionRepository = new StaticEntityRepository([[TestDataIds::id(PromotionSeeder::PERCENTAGE_PROMOTION)], []]);
        /** @var StaticEntityRepository<RuleCollection> $ruleRepository */
        $ruleRepository = new StaticEntityRepository([]);
        $seeder = new PromotionSeeder($promotionRepository, $ruleRepository);

        static::assertTrue($seeder->exists(Context::createDefaultContext()));
        static::assertFalse($seeder->exists(Context::createDefaultContext()));
    }

    public function testRemoveReportsWhetherAnyPromotionExisted(): void
    {
        $promotionId = TestDataIds::id(PromotionSeeder::PERCENTAGE_PROMOTION);
        /** @var StaticEntityRepository<PromotionCollection> $promotionRepository */
        $promotionRepository = new StaticEntityRepository([[$promotionId], []]);
        /** @var StaticEntityRepository<RuleCollection> $ruleRepository */
        $ruleRepository = new StaticEntityRepository([[], []]);
        $seeder = new PromotionSeeder($promotionRepository, $ruleRepository);

        static::assertTrue($seeder->remove(Context::createDefaultContext()));
        static::assertSame([[['id' => $promotionId]]], $promotionRepository->deletes);
        static::assertSame([], $ruleRepository->deletes);
        static::assertFalse($seeder->remove(Context::createDefaultContext()));
    }
}
