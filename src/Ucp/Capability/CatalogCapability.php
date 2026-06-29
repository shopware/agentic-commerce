<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Adapter\CatalogAdapterInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
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
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CATALOG, 'Catalog capability is disabled for this sales channel.');

        return new CatalogSearchResponse($this->adapter->search($request, $context));
    }

    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CATALOG, 'Catalog capability is disabled for this sales channel.');

        return $this->adapter->lookup($request, $context);
    }

    public function getProduct(string $id, RequestContext $context): Product
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CATALOG, 'Catalog capability is disabled for this sales channel.');

        return $this->adapter->getProduct($id, $context);
    }
}
