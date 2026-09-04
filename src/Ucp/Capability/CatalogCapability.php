<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Adapter\CatalogAdapterInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchResponse;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class CatalogCapability implements CatalogCapabilityInterface
{
    public function __construct(
        private readonly CatalogAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_CATALOG);
    }

    public function search(CatalogSearchRequest $request, RequestContext $context): CatalogSearchResponse
    {
        // Guarded per operation now that search and lookup are separate capabilities. A peer
        // that negotiated only one of them must not reach the other through this class.
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CATALOG_SEARCH, 'Catalog search capability is disabled for this sales channel.');

        return new CatalogSearchResponse(items: $this->adapter->search($request, $context));
    }

    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CATALOG_LOOKUP, 'Catalog lookup capability is disabled for this sales channel.');

        return $this->adapter->lookup($request, $context);
    }

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product
    {
        // Product detail is the lookup capability, not a third one: both of its schemas come
        // from catalog_lookup.json, which is why no release defines an id for it.
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CATALOG_LOOKUP, 'Catalog lookup capability is disabled for this sales channel.');

        return $this->adapter->getProduct($request, $context);
    }
}
