<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Checkout\Cart\Rule\LineItemRule;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionDiscount\PromotionDiscountEntity;
use Shopware\Core\Checkout\Promotion\PromotionCollection;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\Rule;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * Two code promotions and one automatic promotion. The automatic one applies only to carts holding the
 * tier-price product, so it leaves every other cart in the target sales channels untouched.
 *
 * @internal
 */
#[Package('framework')]
final class PromotionSeeder implements TestDataSeederInterface
{
    use DeletesExistingIds;

    public const PERCENTAGE_CODE = 'AC-TEST-10';
    public const FREE_SHIPPING_CODE = 'AC-TEST-SHIP';
    public const PERCENTAGE_PROMOTION = 'promotion.percentage-code';
    public const FREE_SHIPPING_PROMOTION = 'promotion.free-shipping-code';
    public const AUTOMATIC_PROMOTION = 'promotion.automatic';
    public const RULE_CART_HAS_TIER_PRODUCT = 'rule.cart-has-tier-product';

    /**
     * @param EntityRepository<PromotionCollection> $promotionRepository
     * @param EntityRepository<RuleCollection>      $ruleRepository
     */
    public function __construct(
        private readonly EntityRepository $promotionRepository,
        private readonly EntityRepository $ruleRepository,
    ) {
    }

    public function label(): string
    {
        return 'Promotions: code, free shipping, automatic';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function exists(Context $context): bool
    {
        return $this->idExists($this->promotionRepository, TestDataIds::id(self::PERCENTAGE_PROMOTION), $context);
    }

    public function create(array $salesChannelIds, Context $context): array
    {
        $this->ruleRepository->upsert([[
            'id' => TestDataIds::id(self::RULE_CART_HAS_TIER_PRODUCT),
            'name' => TestDataIds::name('Cart contains the tier-price product'),
            'description' => 'Limits the automatic test promotion to carts with the tier-price test product.',
            'priority' => 100,
            'customFields' => TestDataIds::marker(),
            'conditions' => RuleConditions::single(self::RULE_CART_HAS_TIER_PRODUCT, LineItemRule::RULE_NAME, [
                'operator' => Rule::OPERATOR_EQ,
                'identifiers' => [TestDataIds::id(ProductSeeder::TIER_PRICES)],
            ]),
        ]], $context);

        $this->promotionRepository->create([
            [
                ...$this->promotion(self::PERCENTAGE_PROMOTION, '10 % off with code', $salesChannelIds),
                'useCodes' => true,
                'code' => self::PERCENTAGE_CODE,
                'discounts' => [$this->discount(self::PERCENTAGE_PROMOTION, PromotionDiscountEntity::SCOPE_CART, PromotionDiscountEntity::TYPE_PERCENTAGE, 10.0)],
            ],
            [
                ...$this->promotion(self::FREE_SHIPPING_PROMOTION, 'Free shipping with code', $salesChannelIds),
                'useCodes' => true,
                'code' => self::FREE_SHIPPING_CODE,
                'discounts' => [$this->discount(self::FREE_SHIPPING_PROMOTION, PromotionDiscountEntity::SCOPE_DELIVERY, PromotionDiscountEntity::TYPE_PERCENTAGE, 100.0)],
            ],
            [
                ...$this->promotion(self::AUTOMATIC_PROMOTION, '5.00 off carts with Bulk Pens', $salesChannelIds),
                'useCodes' => false,
                'cartRules' => [['id' => TestDataIds::id(self::RULE_CART_HAS_TIER_PRODUCT)]],
                'discounts' => [$this->discount(self::AUTOMATIC_PROMOTION, PromotionDiscountEntity::SCOPE_CART, PromotionDiscountEntity::TYPE_ABSOLUTE, 5.0)],
            ],
        ], $context);

        return [
            self::PERCENTAGE_CODE.': 10 % off the cart',
            self::FREE_SHIPPING_CODE.': free shipping',
            'Automatic: 5.00 off carts with '.TestDataIds::productNumber('TIER-PRICES'),
        ];
    }

    public function remove(Context $context): bool
    {
        $removed = $this->deleteExisting($this->promotionRepository, [
            TestDataIds::id(self::PERCENTAGE_PROMOTION),
            TestDataIds::id(self::FREE_SHIPPING_PROMOTION),
            TestDataIds::id(self::AUTOMATIC_PROMOTION),
        ], $context);

        return $this->deleteExisting($this->ruleRepository, [TestDataIds::id(self::RULE_CART_HAS_TIER_PRODUCT)], $context) || $removed;
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return array<string, mixed>
     */
    private function promotion(string $promotionKey, string $name, array $salesChannelIds): array
    {
        return [
            'id' => TestDataIds::id($promotionKey),
            'name' => TestDataIds::name($name),
            'active' => true,
            'customFields' => TestDataIds::marker(),
            'salesChannels' => array_map(static fn (string $salesChannelId): array => [
                'id' => TestDataIds::id($promotionKey.'.sales-channel.'.$salesChannelId),
                'salesChannelId' => $salesChannelId,
                'priority' => 1,
            ], $salesChannelIds),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function discount(string $promotionKey, string $scope, string $type, float $value): array
    {
        return [
            'id' => TestDataIds::id($promotionKey.'.discount'),
            'scope' => $scope,
            'type' => $type,
            'value' => $value,
            'considerAdvancedRules' => false,
        ];
    }
}
