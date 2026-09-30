<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\TestData;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Promotion\PromotionCollection;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Rule\RuleCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Test\TestDefaults;
use Swag\AgenticCommerce\TestData\Command\TestDataCommand;
use Swag\AgenticCommerce\TestData\Seeder\DynamicAccessSeeder;
use Swag\AgenticCommerce\TestData\Seeder\FoundationSeeder;
use Swag\AgenticCommerce\TestData\Seeder\PromotionSeeder;
use Swag\AgenticCommerce\TestData\TestDataIds;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class TestDataCommandTest extends TestCase
{
    use IntegrationTestBehaviour;

    private const PRODUCT_NUMBERS = [
        'SWAG-AC-TEST-PHYSICAL-PDF',
        'SWAG-AC-TEST-DIGITAL-MP3',
        'SWAG-AC-TEST-DIGITAL-VINYL',
        'SWAG-AC-TEST-TIER-PRICES',
        'SWAG-AC-TEST-CUSTOM-FIELDS',
        'SWAG-AC-TEST-PROPERTIES',
    ];

    public function testCreatesTheDataSetThroughTheDalAndRemovesItCompletely(): void
    {
        $context = Context::createDefaultContext();
        $command = new CommandTester($this->command());

        $command->execute(['--sales-channel' => [TestDefaults::SALES_CHANNEL]], ['interactive' => false]);
        $display = $command->getDisplay();
        static::assertSame(0, $command->getStatusCode(), $display);
        static::assertMatchesRegularExpression('/Products: .*\s+created/', $display);
        static::assertMatchesRegularExpression('/Promotions: .*\s+created/', $display);
        static::assertMatchesRegularExpression('/Category tree\s+created/', $display);
        static::assertMatchesRegularExpression('/\(Dynamic Access\)\s+(created|skipped)/', $display);
        static::assertMatchesRegularExpression('/\(Shopware Commercial\)\s+(created|skipped)/', $display);

        $productByNumber = $this->productsByNumber($context);
        static::assertSame(self::PRODUCT_NUMBERS, array_values(array_intersect(self::PRODUCT_NUMBERS, array_keys($productByNumber))));
        static::assertTrue(self::isDigital($productByNumber['SWAG-AC-TEST-PHYSICAL-PDF']));
        static::assertTrue(self::isDigital($productByNumber['SWAG-AC-TEST-DIGITAL-MP3']));
        static::assertFalse(self::isDigital($productByNumber['SWAG-AC-TEST-DIGITAL-VINYL']));

        $tierPrices = $productByNumber['SWAG-AC-TEST-TIER-PRICES']->getPrices();
        static::assertNotNull($tierPrices);
        static::assertCount(3, $tierPrices);
        static::assertSame([TestDataIds::id(FoundationSeeder::RULE_SALES_CHANNEL)], array_values(array_unique($tierPrices->map(static fn ($price): string => $price->getRuleId()))));

        $customFields = $productByNumber['SWAG-AC-TEST-CUSTOM-FIELDS']->getCustomFields() ?? [];
        static::assertSame('Wash cold, dry flat.', $customFields[FoundationSeeder::CUSTOM_FIELD_CARE_NOTE] ?? null);
        static::assertEquals(2, $customFields[FoundationSeeder::CUSTOM_FIELD_WARRANTY_YEARS] ?? null);

        static::assertCount(2, $productByNumber['SWAG-AC-TEST-PROPERTIES']->getProperties() ?? []);

        $promotionCodes = $this->promotionRepository()->search(
            (new Criteria())->addFilter(new EqualsAnyFilter('code', [PromotionSeeder::PERCENTAGE_CODE, PromotionSeeder::FREE_SHIPPING_CODE])),
            $context,
        )->getEntities()->map(static fn ($promotion): ?string => $promotion->getCode());
        static::assertEqualsCanonicalizing([PromotionSeeder::PERCENTAGE_CODE, PromotionSeeder::FREE_SHIPPING_CODE], array_values($promotionCodes));

        $command->execute(['--remove' => true], ['interactive' => false]);
        static::assertSame(0, $command->getStatusCode(), $command->getDisplay());

        static::assertSame(0, $this->productRepository()->searchIds(
            (new Criteria())->addFilter(new PrefixFilter('productNumber', TestDataIds::PRODUCT_NUMBER_PREFIX)),
            $context,
        )->getTotal());
        static::assertSame(0, $this->promotionRepository()->searchIds(new Criteria([TestDataIds::id(PromotionSeeder::PERCENTAGE_PROMOTION), TestDataIds::id(PromotionSeeder::FREE_SHIPPING_PROMOTION), TestDataIds::id(PromotionSeeder::AUTOMATIC_PROMOTION)]), $context)->getTotal());
        static::assertSame(0, $this->ruleRepository()->searchIds(new Criteria([TestDataIds::id(FoundationSeeder::RULE_SALES_CHANNEL), TestDataIds::id(PromotionSeeder::RULE_CART_HAS_TIER_PRODUCT), TestDataIds::id(DynamicAccessSeeder::RULE_CUSTOMER_LOGGED_IN)]), $context)->getTotal());
        static::assertSame(0, $this->countTestCategories($context));
        static::assertSame(0, $this->mediaRepository()->searchIds(new Criteria([TestDataIds::id(FoundationSeeder::MEDIA_GUIDE), TestDataIds::id(FoundationSeeder::MEDIA_ALBUM)]), $context)->getTotal());
    }

    private function countTestCategories(Context $context): int
    {
        $repository = static::getContainer()->get('category.repository');
        static::assertInstanceOf(EntityRepository::class, $repository);

        return $repository->searchIds((new Criteria())->addFilter(new PrefixFilter('name', TestDataIds::NAME_PREFIX)), $context)->getTotal();
    }

    /**
     * @return array<string, ProductEntity>
     */
    private function productsByNumber(Context $context): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsAnyFilter('productNumber', self::PRODUCT_NUMBERS))
            ->addAssociation('prices')
            ->addAssociation('properties');

        $productByNumber = [];
        foreach ($this->productRepository()->search($criteria, $context)->getEntities() as $product) {
            $productByNumber[$product->getProductNumber()] = $product;
        }

        return $productByNumber;
    }

    // `type` exists from Shopware 6.7.7; before that the downloads alone set the `is-download` state.
    private static function isDigital(ProductEntity $product): bool
    {
        $type = $product->has('type') ? $product->get('type') : null;
        if (\is_string($type)) {
            return 'digital' === $type;
        }

        $states = $product->get('states');

        return \is_array($states) && \in_array('is-download', $states, true);
    }

    private function command(): TestDataCommand
    {
        $command = static::getContainer()->get(TestDataCommand::class);
        static::assertInstanceOf(TestDataCommand::class, $command);

        return $command;
    }

    /**
     * @return EntityRepository<ProductCollection>
     */
    private function productRepository(): EntityRepository
    {
        /** @var EntityRepository<ProductCollection> $repository */
        $repository = static::getContainer()->get('product.repository');

        return $repository;
    }

    /**
     * @return EntityRepository<PromotionCollection>
     */
    private function promotionRepository(): EntityRepository
    {
        /** @var EntityRepository<PromotionCollection> $repository */
        $repository = static::getContainer()->get('promotion.repository');

        return $repository;
    }

    /**
     * @return EntityRepository<RuleCollection>
     */
    private function ruleRepository(): EntityRepository
    {
        /** @var EntityRepository<RuleCollection> $repository */
        $repository = static::getContainer()->get('rule.repository');

        return $repository;
    }

    /**
     * @return EntityRepository<MediaCollection>
     */
    private function mediaRepository(): EntityRepository
    {
        /** @var EntityRepository<MediaCollection> $repository */
        $repository = static::getContainer()->get('media.repository');

        return $repository;
    }
}
