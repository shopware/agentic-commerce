<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\RequestContext;

final class ShopwareCatalogGateway
{
    public function __construct(
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly ContextTokenGenerator $contextTokenGenerator,
        private readonly AbstractProductSearchRoute $productSearchRoute,
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

            $products[] = $this->mapper->toProduct($product);
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
        $products = [];

        foreach ($ids as $id) {
            $products[] = $this->getProductForContext($id, $context);
        }

        return $products;
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

        return $this->mapper->toProduct($response->getProduct());
    }
}
