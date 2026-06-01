<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Adapter;

use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCatalogGateway;
use Ucp\Sdk\Adapter\CatalogAdapterInterface;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\RequestContext;

final readonly class ShopwareCatalogAdapter implements CatalogAdapterInterface
{
    public function __construct(
        private ShopwareCatalogGateway $gateway,
    ) {
    }

    public function search(CatalogSearchRequest $request, RequestContext $context): array
    {
        return $this->gateway->search($request->query, $request->limit, $context);
    }

    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        return $this->gateway->lookup($request->ids, $context);
    }

    public function getProduct(string $id, RequestContext $context): Product
    {
        return $this->gateway->getProduct($id, $context);
    }
}
