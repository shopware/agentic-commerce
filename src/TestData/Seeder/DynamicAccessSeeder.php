<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Checkout\Customer\Rule\CustomerLoggedInRule;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\TestDataEnvironment;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * A product Dynamic Access shows to logged-in customers only, so a guest agent must not find or buy it.
 *
 * @internal
 */
#[Package('framework')]
final class DynamicAccessSeeder implements TestDataSeederInterface
{
    use DeletesExistingIds;

    public const RULE_CUSTOMER_LOGGED_IN = 'rule.customer-logged-in';
    public const MEMBERS_ONLY_PRODUCT = 'product.members-only';
    public const NUMBER_SUFFIX = 'MEMBERS-ONLY';

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<RuleCollection>    $ruleRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $ruleRepository,
        private readonly ProductReferences $productReferences,
        private readonly TestDataEnvironment $environment,
    ) {
    }

    public function label(): string
    {
        return 'Product visible to logged-in customers only (Dynamic Access)';
    }

    public function unavailableReason(): ?string
    {
        return $this->environment->dynamicAccessUnavailableReason();
    }

    public function exists(Context $context): bool
    {
        return $this->idExists($this->productRepository, TestDataIds::id(self::MEMBERS_ONLY_PRODUCT), $context);
    }

    public function create(array $salesChannelIds, PickedProducts $pickedProducts, Context $context): array
    {
        $ruleId = TestDataIds::id(self::RULE_CUSTOMER_LOGGED_IN);

        $this->ruleRepository->upsert([[
            'id' => $ruleId,
            'name' => TestDataIds::prefixedName('Customer logged in'),
            'description' => 'Dynamic Access shows the members-only test product to logged-in customers only.',
            'priority' => 100,
            'customFields' => TestDataIds::markerCustomField(),
            'conditions' => RuleConditions::single(self::RULE_CUSTOMER_LOGGED_IN, CustomerLoggedInRule::RULE_NAME, ['isLoggedIn' => true]),
        ]], $context);

        $builder = $this->productReferences->payloadBuilder($pickedProducts, $salesChannelIds, $context);
        $this->productRepository->create([[
            ...$builder->productPayload(self::MEMBERS_ONLY_PRODUCT, self::NUMBER_SUFFIX),
            TestDataEnvironment::DYNAMIC_ACCESS_PRODUCT_FIELD => [['id' => $ruleId]],
        ]], $context);

        return [$builder->reportLine(self::MEMBERS_ONLY_PRODUCT, self::NUMBER_SUFFIX).', rule '.TestDataIds::prefixedName('Customer logged in')];
    }

    public function remove(Context $context): bool
    {
        $hasRemovedAny = $this->deleteExisting($this->productRepository, [TestDataIds::id(self::MEMBERS_ONLY_PRODUCT)], $context);

        return $this->deleteExisting($this->ruleRepository, [TestDataIds::id(self::RULE_CUSTOMER_LOGGED_IN)], $context) || $hasRemovedAny;
    }
}
