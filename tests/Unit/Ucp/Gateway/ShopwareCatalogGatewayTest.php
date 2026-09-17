<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductListRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\ProductListResponse;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\StaticSalesChannelContextService;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCatalogGateway;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\Catalog\Product as UcpProduct;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareCatalogGatewayTest extends TestCase
{
    #[Test]
    public function testSearchClampsRequestedLimitToConfiguredCatalogLimit(): void
    {
        $criteriaLimits = [];
        $requestLimits = [];
        $products = [
            $this->product('product-a', 'A', 10.0),
            $this->product('product-b', 'B', 20.0),
            $this->product('product-c', 'C', 30.0),
        ];
        $searchRoute = $this->createMock(AbstractProductSearchRoute::class);
        $searchRoute->method('load')->willReturnCallback(
            function (Request $request, SalesChannelContext $context, Criteria $criteria) use (&$criteriaLimits, &$requestLimits, $products): ProductSearchRouteResponse {
                $criteriaLimits[] = $criteria->getLimit();
                $requestLimits[] = $request->query->getInt('limit');

                return $this->searchResponse($products, $criteria);
            },
        );
        $gateway = $this->gateway(2, searchRoute: $searchRoute);

        $products = $gateway->search('speaker', 1000, new RequestContext('shop.test'));

        self::assertSame([2], $criteriaLimits);
        self::assertSame([2], $requestLimits);
        self::assertSame(['product-a', 'product-b'], array_map(static fn (UcpProduct $product): string => $product->id, $products));
    }

    #[Test]
    public function testLookupClampsIdsAndLoadsProductsInOneBatch(): void
    {
        // Annotated because PHPStan cannot follow a by-reference mutation from inside the
        // closure below, and infers a shape narrow enough to call the assertion impossible.
        /** @var list<list<string>> $criteriaIds */
        $criteriaIds = [];
        $products = [
            $this->product('product-b', 'B', 20.0),
            $this->product('product-a', 'A', 10.0),
            $this->product('product-c', 'C', 30.0),
        ];
        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            function (Criteria $criteria, SalesChannelContext $context) use (&$criteriaIds, $products): ProductListResponse {
                $ids = [];
                foreach ($criteria->getIds() as $id) {
                    $ids[] = $id;
                }

                $criteriaIds[] = $ids;

                return $this->listResponse(array_values(array_filter(
                    $products,
                    static fn (SalesChannelProductEntity $product): bool => \in_array($product->getId(), $ids, true),
                )), $criteria);
            },
        );
        $gateway = $this->gateway(2, listRoute: $listRoute);

        $products = $gateway->lookup(['product-a', 'product-b', 'product-c'], new RequestContext('shop.test'));

        self::assertSame([['product-a', 'product-b']], $criteriaIds);
        self::assertSame(['product-a', 'product-b'], array_map(static fn (UcpProduct $product): string => $product->id, $products));

        self::assertSame([['id' => 'product-a', 'match' => 'exact']], self::variantInputs($products[0]->extra));
    }

    #[Test]
    public function testCatalogPricesUseTheSalesChannelCurrency(): void
    {
        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            fn (Criteria $criteria, SalesChannelContext $context): ProductListResponse => $this->listResponse(
                [$this->product('product-a', 'A', 19.99)],
                $criteria,
            ),
        );
        $gateway = $this->gateway(10, listRoute: $listRoute);

        $products = $gateway->lookup(['product-a'], new RequestContext('shop.test'));

        self::assertCount(1, $products);
        self::assertSame('USD', $products[0]->currency);

        $payload = $products[0]->toArray();
        self::assertSame(['amount' => 1999, 'currency' => 'USD'], $payload['price_range']['min']);
        self::assertSame(['amount' => 1999, 'currency' => 'USD'], $payload['variants'][0]['price'] ?? null);
    }

    /**
     * UCP's `query` is optional free text. Shopware's search route answers an empty term with
     * nothing, so an agent opening with a blank search -- which the conformance agent does --
     * was told the shop had no products. An empty query lists the catalog instead.
     */
    public function testAnEmptyQueryListsTheCatalogInsteadOfSearchingForNothing(): void
    {
        $products = [
            $this->product('product-a', 'A', 10.0),
            $this->product('product-b', 'B', 20.0),
        ];
        $searchRoute = $this->createMock(AbstractProductSearchRoute::class);
        $searchRoute->expects(self::never())->method('load');
        $criteriaSeen = null;
        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            function (Criteria $criteria, SalesChannelContext $context) use (&$criteriaSeen, $products): ProductListResponse {
                $criteriaSeen = $criteria;

                return $this->listResponse($products, $criteria);
            },
        );
        $gateway = $this->gateway(50, searchRoute: $searchRoute, listRoute: $listRoute);

        $listed = $gateway->search('   ', 2, new RequestContext('shop.test'));

        self::assertSame(['product-a', 'product-b'], array_map(static fn (UcpProduct $product): string => $product->id, $listed));
        self::assertInstanceOf(Criteria::class, $criteriaSeen);
        self::assertSame(2, $criteriaSeen->getLimit());
        self::assertSame(['name', 'id'], array_map(static fn ($sorting) => $sorting->getField(), $criteriaSeen->getSorting()), 'A listing without a term needs a stable order to page over.');
    }

    #[Test]
    public function testCatalogExposesThePlainProductDescription(): void
    {
        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            fn (Criteria $criteria, SalesChannelContext $context): ProductListResponse => $this->listResponse(
                [$this->product('product-a', 'A', 19.99, '<p>A lightweight <strong>everyday</strong> shoe.</p>')],
                $criteria,
            ),
        );
        $gateway = $this->gateway(10, listRoute: $listRoute);

        $products = $gateway->lookup(['product-a'], new RequestContext('shop.test'));

        self::assertCount(1, $products);
        self::assertSame('A lightweight everyday shoe.', $products[0]->description);

        $payload = $products[0]->toArray();
        self::assertSame(['plain' => 'A lightweight everyday shoe.'], $payload['description']);
        self::assertSame(['plain' => 'A lightweight everyday shoe.'], $payload['variants'][0]['description'] ?? null);
    }

    #[Test]
    public function testCatalogDescriptionFallsBackToTheTitleWhenAbsent(): void
    {
        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            fn (Criteria $criteria, SalesChannelContext $context): ProductListResponse => $this->listResponse(
                [$this->product('product-a', 'Runner Pro', 19.99)],
                $criteria,
            ),
        );
        $gateway = $this->gateway(10, listRoute: $listRoute);

        $products = $gateway->lookup(['product-a'], new RequestContext('shop.test'));

        self::assertCount(1, $products);
        self::assertNull($products[0]->description);
        self::assertSame(['plain' => 'Runner Pro'], $products[0]->toArray()['description']);
    }

    private function gateway(
        int $catalogResultLimit,
        ?AbstractProductSearchRoute $searchRoute = null,
        ?AbstractProductListRoute $listRoute = null,
    ): ShopwareCatalogGateway {
        $salesChannelContext = $this->createSalesChannelContext();
        $config = UcpConfig::fromArray(['catalogResultLimit' => $catalogResultLimit]);

        $configRepository = $this->createMock(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn($config);

        $legacyConfigStore = $this->createMock(LegacyConfigStoreInterface::class);

        return new ShopwareCatalogGateway(
            $this->contextResolver($salesChannelContext),
            new ContextTokenGenerator(),
            new UcpConfigService($configRepository, $legacyConfigStore),
            $searchRoute ?? $this->createMock(AbstractProductSearchRoute::class),
            $listRoute ?? $this->createMock(AbstractProductListRoute::class),
            $this->createMock(AbstractProductDetailRoute::class),
            new ShopwareDataMapper(),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function variantInputs(array $extra): mixed
    {
        $variants = $extra['variants'] ?? null;
        if (!\is_array($variants)) {
            return null;
        }

        $variant = $variants[0] ?? null;
        if (!\is_array($variant)) {
            return null;
        }

        return $variant['inputs'] ?? null;
    }

    private function createSalesChannelContext(): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setId('currency-id');
        $currency->setIsoCode('USD');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');
        $context->method('getCurrency')->willReturn($currency);

        return $context;
    }

    private function contextResolver(SalesChannelContext $salesChannelContext): SalesChannelContextResolver
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('domain-id');
        $domain->setUrl('https://shop.test');
        $domain->setSalesChannelId('sales-channel-id');
        $domain->setLanguageId('language-id');
        $domain->setCurrencyId('currency-id');

        /** @var EntityRepository<SalesChannelDomainCollection>&MockObject $domainRepository */
        $domainRepository = $this->createMock(EntityRepository::class);
        $domainRepository->method('search')->willReturn(new EntitySearchResult(
            'sales_channel_domain',
            1,
            new SalesChannelDomainCollection([$domain]),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        ));

        $contextPersister = $this->createMock(SalesChannelContextPersister::class);
        $contextPersister->method('load')->willReturn([]);

        return new SalesChannelContextResolver(
            new SalesChannelDomainResolver($domainRepository),
            new StaticSalesChannelContextService($salesChannelContext),
            $contextPersister,
        );
    }

    /**
     * Browsing used to answer with the parent of every variant product plus one row per variant.
     * The parent cannot be bought, so a cart built from it comes back empty, and the variants all
     * wore the parent's name.
     */
    #[Test]
    public function testBrowsingAsksForOneRowPerVariantGroupAndSkipsParents(): void
    {
        $captured = null;
        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            function (Criteria $criteria, SalesChannelContext $context) use (&$captured): ProductListResponse {
                $captured ??= $criteria;

                return $this->listResponse([], $criteria);
            },
        );

        $this->gateway(10, listRoute: $listRoute)->search('', 10, new RequestContext('shop.test'));

        self::assertInstanceOf(Criteria::class, $captured);

        $groupedFields = array_map(
            static fn (FieldGrouping $grouping): string => $grouping->getField(),
            $captured->getGroupFields(),
        );
        self::assertSame(['displayGroup'], $groupedFields, 'one row per variant group');

        // VariantListingUpdater gives a parent with children display_group = NULL, so excluding
        // the null ones is what removes parents from the answer.
        self::assertStringContainsString('displayGroup', json_encode($captured->getFilters(), \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function testVariantTitlesCarryTheOptionsThatDistinguishThem(): void
    {
        $variant = $this->product('variant-a', 'Acoustic Guitar', 25.08);
        $variant->assign(['variation' => [
            ['group' => 'Color', 'option' => 'Yellow'],
            ['group' => 'Material', 'option' => 'Spruce Top'],
        ]]);

        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            fn (Criteria $criteria, SalesChannelContext $context): ProductListResponse => $this->listResponse([$variant], $criteria),
        );

        $products = $this->gateway(10, listRoute: $listRoute)->lookup(['variant-a'], new RequestContext('shop.test'));

        self::assertSame('Acoustic Guitar (Color: Yellow, Material: Spruce Top)', $products[0]->title);
    }

    /**
     * A parent is not purchasable, so answering a lookup with its id -- which is what asking for
     * one used to do, listed as its own variant -- hands the agent something the cart will drop.
     */
    #[Test]
    public function testLookingUpAParentAnswersWithAPurchasableVariant(): void
    {
        $parent = $this->product('parent-a', 'Acoustic Guitar', 25.08);
        $parent->setChildCount(2);

        $variant = $this->product('variant-a', 'Acoustic Guitar', 25.08);
        $variant->setParentId('parent-a');

        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            function (Criteria $criteria, SalesChannelContext $context) use ($parent, $variant): ProductListResponse {
                // First the requested ids, then the representative lookup by parentId, then the
                // replacement load by the representative's own id.
                if (\in_array('parent-a', $criteria->getIds(), true)) {
                    return $this->listResponse([$parent], $criteria);
                }

                return $this->listResponse([$variant], $criteria);
            },
        );

        $products = $this->gateway(10, listRoute: $listRoute)->lookup(['parent-a'], new RequestContext('shop.test'));

        self::assertCount(1, $products);
        self::assertSame('variant-a', $products[0]->id, 'the answer is something the agent can buy');
        self::assertSame(
            [['id' => 'parent-a', 'match' => 'exact']],
            self::variantInputs($products[0]->extra),
            'the requested id stays the lookup input',
        );
    }

    /**
     * The fix for `catalog.product` answering a different variant on every call is the ordering,
     * not the resolving: ProductDetailRoute's findBestVariant() resolves a parent too, but orders
     * by availability and price with no tiebreaker, so variants sharing both came back in
     * whatever order the database felt like. Assert the ordering, or the determinism the
     * representative lookup exists for is the one thing nothing covers.
     */
    #[Test]
    public function testTheVariantRepresentingAParentIsChosenByAFullyDeterministicOrder(): void
    {
        $sorting = $this->captureRepresentativeCriteria()->getSorting();

        self::assertSame(
            ['parentId', 'available', 'price', 'id'],
            array_map(static fn (FieldSorting $field): string => $field->getField(), $sorting),
        );
        self::assertSame(
            [FieldSorting::ASCENDING, FieldSorting::DESCENDING, FieldSorting::ASCENDING, FieldSorting::ASCENDING],
            array_map(static fn (FieldSorting $field): string => $field->getDirection(), $sorting),
            'buyable first, then cheapest, then id -- the tiebreaker core is missing',
        );
    }

    /**
     * Unbounded, this loads every variant of every parent to keep one id apiece, so a page of
     * parents that each have many variants hydrates the full cross-product to select a handful.
     */
    #[Test]
    public function testTheRepresentativeLookupIsBoundedPerParentRatherThanByTotalVariantCount(): void
    {
        self::assertSame(50, $this->captureRepresentativeCriteria()->getLimit());
    }

    /**
     * The bound must not cost correctness: a parent whose variants did not fit in the window
     * still has to resolve, or the lookup answers with the parent id again and the cart drops it.
     */
    #[Test]
    public function testAParentCrowdedOutOfTheBoundedWindowIsStillResolvedOnItsOwn(): void
    {
        $parent = $this->product('parent-b', 'Bass Guitar', 30.0);
        $parent->setChildCount(2);

        $variant = $this->product('variant-b', 'Bass Guitar', 30.0);
        $variant->setParentId('parent-b');

        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            function (Criteria $criteria, SalesChannelContext $context) use ($parent, $variant): ProductListResponse {
                if (\in_array('parent-b', $criteria->getIds(), true)) {
                    return $this->listResponse([$parent], $criteria);
                }

                if (\in_array('variant-b', $criteria->getIds(), true)) {
                    return $this->listResponse([$variant], $criteria);
                }

                // The batched window came back without this parent in it -- another parent's
                // variants filled it. Only the single-parent query that follows finds one.
                return 1 === $criteria->getLimit()
                    ? $this->listResponse([$variant], $criteria)
                    : $this->listResponse([], $criteria);
            },
        );

        $products = $this->gateway(10, listRoute: $listRoute)->lookup(['parent-b'], new RequestContext('shop.test'));

        self::assertCount(1, $products);
        self::assertSame('variant-b', $products[0]->id, 'the bound may cost a query, not an answer');
    }

    /**
     * The criteria the representative lookup hands the product list route: the one carrying the
     * sorting, as opposed to the id reads on either side of it.
     */
    private function captureRepresentativeCriteria(): Criteria
    {
        $captured = null;

        $parent = $this->product('parent-a', 'Acoustic Guitar', 25.08);
        $parent->setChildCount(2);

        $variant = $this->product('variant-a', 'Acoustic Guitar', 25.08);
        $variant->setParentId('parent-a');

        $listRoute = $this->createMock(AbstractProductListRoute::class);
        $listRoute->method('load')->willReturnCallback(
            function (Criteria $criteria, SalesChannelContext $context) use (&$captured, $parent, $variant): ProductListResponse {
                if ([] !== $criteria->getSorting()) {
                    $captured ??= $criteria;

                    return $this->listResponse([$variant], $criteria);
                }

                return \in_array('parent-a', $criteria->getIds(), true)
                    ? $this->listResponse([$parent], $criteria)
                    : $this->listResponse([$variant], $criteria);
            },
        );

        $this->gateway(10, listRoute: $listRoute)->lookup(['parent-a'], new RequestContext('shop.test'));

        self::assertInstanceOf(Criteria::class, $captured);

        return $captured;
    }

    private function product(string $id, string $name, float $price, ?string $description = null): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId($id);
        $product->setName($name);
        $product->setProductNumber($id);
        $product->setCalculatedPrice(new CalculatedPrice($price, $price, new CalculatedTaxCollection(), new TaxRuleCollection()));

        if (null !== $description) {
            $product->setDescription($description);
        }

        return $product;
    }

    /**
     * @param list<SalesChannelProductEntity> $products
     */
    private function searchResponse(array $products, Criteria $criteria): ProductSearchRouteResponse
    {
        return new ProductSearchRouteResponse(new ProductListingResult(
            'product',
            \count($products),
            new ProductCollection($products),
            null,
            $criteria,
            Context::createDefaultContext(),
        ));
    }

    /**
     * @param list<SalesChannelProductEntity> $products
     */
    private function listResponse(array $products, Criteria $criteria): ProductListResponse
    {
        // ProductCollection is declared over ProductEntity, so building one from
        // SalesChannelProductEntity narrows it to ProductCollection<SalesChannelProductEntity>
        // -- which the invariant EntitySearchResult<ProductCollection> the route returns does
        // not accept. The runtime type is right; only the inferred generic is too narrow.
        /** @var EntitySearchResult<ProductCollection> $result */
        $result = new EntitySearchResult(
            'product',
            \count($products),
            new ProductCollection($products),
            null,
            $criteria,
            Context::createDefaultContext(),
        );

        return new ProductListResponse($result);
    }
}
