<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Checkout\Customer\Rule\CustomerLoggedInRule;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
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

    /**
     * @param EntityRepository<ProductCollection> $productRepository
     * @param EntityRepository<RuleCollection>    $ruleRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $ruleRepository,
        private readonly TestDataTax $tax,
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

    public function create(array $salesChannelIds, Context $context): array
    {
        $ruleId = TestDataIds::id(self::RULE_CUSTOMER_LOGGED_IN);

        $this->ruleRepository->upsert([[
            'id' => $ruleId,
            'name' => TestDataIds::name('Customer logged in'),
            'description' => 'Dynamic Access shows the members-only test product to logged-in customers only.',
            'priority' => 100,
            'customFields' => TestDataIds::marker(),
            'conditions' => RuleConditions::single(self::RULE_CUSTOMER_LOGGED_IN, CustomerLoggedInRule::RULE_NAME, ['isLoggedIn' => true]),
        ]], $context);

        $tax = $this->tax->resolve($context);

        $this->productRepository->create([[
            'id' => TestDataIds::id(self::MEMBERS_ONLY_PRODUCT),
            'productNumber' => TestDataIds::productNumber('MEMBERS-ONLY'),
            'name' => TestDataIds::name('Members-only Notebook'),
            'description' => 'Restricted by Dynamic Access to logged-in customers.',
            'type' => 'physical',
            'active' => true,
            'stock' => 100,
            'weight' => 0.35,
            'taxId' => $tax->getId(),
            'price' => [TestDataTax::price(39.99, $tax)],
            'customFields' => TestDataIds::marker(),
            'visibilities' => ProductSeeder::visibilities($salesChannelIds),
            TestDataEnvironment::DYNAMIC_ACCESS_PRODUCT_FIELD => [['id' => $ruleId]],
        ]], $context);

        return [TestDataIds::productNumber('MEMBERS-ONLY').': rule '.TestDataIds::name('Customer logged in')];
    }

    public function remove(Context $context): bool
    {
        $removed = $this->deleteExisting($this->productRepository, [TestDataIds::id(self::MEMBERS_ONLY_PRODUCT)], $context);

        return $this->deleteExisting($this->ruleRepository, [TestDataIds::id(self::RULE_CUSTOMER_LOGGED_IN)], $context) || $removed;
    }
}
