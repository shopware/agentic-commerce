<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Content\Product\SalesChannel\AbstractProductListRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareCatalogGateway
{
    public function __construct(
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly ContextTokenGenerator $contextTokenGenerator,
        private readonly UcpConfigService $configService,
        private readonly AbstractProductSearchRoute $productSearchRoute,
        private readonly AbstractProductListRoute $productListRoute,
        private readonly AbstractProductDetailRoute $productDetailRoute,
        private readonly ShopwareDataMapper $mapper,
    ) {
    }

    /**
     * @return list<\Ucp\Sdk\Model\Catalog\Product>
     */
    public function search(string $query, int $limit, RequestContext $requestContext): array
    {
        $context = $this->contextResolver->resolve($this->contextTokenGenerator->generate(), $requestContext);
        $limit = $this->requestLimit($limit, $context->getSalesChannelId());
        $criteria = new Criteria();
        $criteria->setLimit($limit);

        $response = $this->productSearchRoute->load(new Request([
            'search' => $query,
            'limit' => $limit,
        ]), $context, $criteria);
        $products = [];

        foreach ($response->getListingResult()->getEntities() as $product) {
            if (!$product instanceof SalesChannelProductEntity) {
                continue;
            }

            $products[] = $this->mapper->toProduct($product, $context);
        }

        return \array_slice($products, 0, $limit);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<\Ucp\Sdk\Model\Catalog\Product>
     */
    public function lookup(array $ids, RequestContext $requestContext): array
    {
        $context = $this->contextResolver->resolve($this->contextTokenGenerator->generate(), $requestContext);
        $ids = \array_slice($ids, 0, $this->configService->getConfig($context->getSalesChannelId())->catalogResultLimit);
        if ([] === $ids) {
            return [];
        }

        $response = $this->productListRoute->load(new Criteria($ids), $context);
        $products = [];

        foreach ($response->getProducts() as $product) {
            $products[$product->getId()] = $product;
        }

        $orderedProducts = [];
        foreach ($ids as $id) {
            if (isset($products[$id])) {
                $orderedProducts[] = $this->mapper->toProduct($products[$id], $context, $id);
            }
        }

        return $orderedProducts;
    }

    public function getProduct(string $id, RequestContext $requestContext): \Ucp\Sdk\Model\Catalog\Product
    {
        $context = $this->contextResolver->resolve($this->contextTokenGenerator->generate(), $requestContext);

        return $this->getProductForContext($id, $context);
    }

    private function getProductForContext(
        string $id,
        \Shopware\Core\System\SalesChannel\SalesChannelContext $context,
    ): \Ucp\Sdk\Model\Catalog\Product {
        $response = $this->productDetailRoute->load($id, new Request(), $context, new Criteria([$id]));

        return $this->mapper->toProduct($response->getProduct(), $context);
    }

    private function requestLimit(int $requestedLimit, string $salesChannelId): int
    {
        return min(max(1, $requestedLimit), $this->configService->getConfig($salesChannelId)->catalogResultLimit);
    }
}
