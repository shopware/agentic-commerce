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
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRouteResponse;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingResult;
use Shopware\Core\Content\Product\SalesChannel\ProductListResponse;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
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
        $searchRoute = new RecordingProductSearchRoute([
            $this->product('product-a', 'A', 10.0),
            $this->product('product-b', 'B', 20.0),
            $this->product('product-c', 'C', 30.0),
        ]);
        $gateway = $this->gateway(2, searchRoute: $searchRoute);

        $products = $gateway->search('speaker', 1000, new RequestContext('shop.test'));

        self::assertSame([2], $searchRoute->criteriaLimits);
        self::assertSame([2], $searchRoute->requestLimits);
        self::assertSame(['product-a', 'product-b'], array_map(static fn (UcpProduct $product): string => $product->id, $products));
    }

    #[Test]
    public function testLookupClampsIdsAndLoadsProductsInOneBatch(): void
    {
        $listRoute = new RecordingProductListRoute([
            $this->product('product-b', 'B', 20.0),
            $this->product('product-a', 'A', 10.0),
            $this->product('product-c', 'C', 30.0),
        ]);
        $gateway = $this->gateway(2, listRoute: $listRoute);

        $products = $gateway->lookup(['product-a', 'product-b', 'product-c'], new RequestContext('shop.test'));

        self::assertSame([['product-a', 'product-b']], $listRoute->criteriaIds);
        self::assertSame(['product-a', 'product-b'], array_map(static fn (UcpProduct $product): string => $product->id, $products));
    }

    private function gateway(
        int $catalogResultLimit,
        ?RecordingProductSearchRoute $searchRoute = null,
        ?RecordingProductListRoute $listRoute = null,
    ): ShopwareCatalogGateway {
        $salesChannelContext = $this->createSalesChannelContext();

        return new ShopwareCatalogGateway(
            $this->contextResolver($salesChannelContext),
            new ContextTokenGenerator(),
            new UcpConfigService(
                new StaticCatalogConfigRepository(UcpConfig::fromArray(['catalogResultLimit' => $catalogResultLimit])),
                new NullCatalogLegacyConfigStore(),
            ),
            $searchRoute ?? new RecordingProductSearchRoute([]),
            $listRoute ?? new RecordingProductListRoute([]),
            new FailingProductDetailRoute(),
            new ShopwareDataMapper(),
        );
    }

    private function createSalesChannelContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

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

    private function product(string $id, string $name, float $price): SalesChannelProductEntity
    {
        $product = new SalesChannelProductEntity();
        $product->setId($id);
        $product->setName($name);
        $product->setProductNumber($id);
        $product->setCalculatedPrice(new CalculatedPrice($price, $price, new CalculatedTaxCollection(), new TaxRuleCollection()));

        return $product;
    }
}

/** @internal */
final class RecordingProductSearchRoute extends AbstractProductSearchRoute
{
    /**
     * @var list<int|null>
     */
    public array $criteriaLimits = [];

    /**
     * @var list<int>
     */
    public array $requestLimits = [];

    /**
     * @param list<SalesChannelProductEntity> $products
     */
    public function __construct(private readonly array $products)
    {
    }

    public function getDecorated(): AbstractProductSearchRoute
    {
        throw new \RuntimeException('No decorated route in test.');
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): ProductSearchRouteResponse
    {
        $this->criteriaLimits[] = $criteria->getLimit();
        $this->requestLimits[] = $request->query->getInt('limit');

        return new ProductSearchRouteResponse(new ProductListingResult(
            'product',
            \count($this->products),
            new ProductCollection($this->products),
            null,
            $criteria,
            Context::createDefaultContext(),
        ));
    }
}

/** @internal */
final class RecordingProductListRoute extends AbstractProductListRoute
{
    /**
     * @var list<list<string>>
     */
    public array $criteriaIds = [];

    /**
     * @param list<SalesChannelProductEntity> $products
     */
    public function __construct(private readonly array $products)
    {
    }

    public function getDecorated(): AbstractProductListRoute
    {
        throw new \RuntimeException('No decorated route in test.');
    }

    public function load(Criteria $criteria, SalesChannelContext $context): ProductListResponse
    {
        $ids = $criteria->getIds();
        $this->criteriaIds[] = $ids;
        $products = array_values(array_filter(
            $this->products,
            static fn (SalesChannelProductEntity $product): bool => \in_array($product->getId(), $ids, true),
        ));

        return new ProductListResponse(new EntitySearchResult(
            'product',
            \count($products),
            new ProductCollection($products),
            null,
            $criteria,
            Context::createDefaultContext(),
        ));
    }
}

/** @internal */
final class FailingProductDetailRoute extends AbstractProductDetailRoute
{
    public function getDecorated(): AbstractProductDetailRoute
    {
        throw new \RuntimeException('No decorated route in test.');
    }

    public function load(string $productId, Request $request, SalesChannelContext $context, Criteria $criteria): ProductDetailRouteResponse
    {
        throw new \RuntimeException('Product detail route should not be used by this test.');
    }
}

/** @internal */
final class StaticCatalogConfigRepository implements UcpConfigRepositoryInterface
{
    public function __construct(private readonly UcpConfig $config)
    {
    }

    public function find(string $salesChannelId): ?UcpConfig
    {
        return $this->config;
    }

    public function findMany(array $salesChannelIds): array
    {
        $configs = [];
        foreach ($salesChannelIds as $salesChannelId) {
            $configs[$salesChannelId] = $this->config;
        }

        return $configs;
    }

    public function save(string $salesChannelId, UcpConfig $config): void
    {
    }
}

/** @internal */
final class NullCatalogLegacyConfigStore implements LegacyConfigStoreInterface
{
    public function get(string $key, ?string $salesChannelId): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, ?string $salesChannelId): void
    {
    }
}
