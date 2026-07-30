<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Capability\CatalogCapability;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Ucp\Sdk\Adapter\CatalogAdapterInterface;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class CatalogCapabilityTest extends TestCase
{
    #[Test]
    public function testItForwardsCatalogProductRequestToAdapter(): void
    {
        $adapter = new CatalogProductRequestCapturingAdapter();
        $capability = new CatalogCapability($adapter);
        $request = new CatalogProductRequest(
            'product-1',
            selected: [['name' => 'Color', 'label' => 'Blue']],
            filters: ['price' => ['max' => 15000]],
            preferences: ['Color', 'Size'],
            context: ['address_country' => 'US'],
            signals: ['dev.ucp.user_agent' => 'agent'],
            attribution: ['utm_source' => 'assistant'],
        );

        $product = $capability->getProduct($request, $this->enabledContext());

        self::assertSame('product-1', $product->id);
        self::assertSame($request, $adapter->productRequest);
    }

    private function enabledContext(): RequestContext
    {
        return new RequestContext(
            'shop.test',
            runtimeConfiguration: new RuntimeConfiguration(
                '2026-04-08',
                'https://shop.test',
                enabledCapabilities: [UcpCapabilityCatalog::DESCRIPTOR_CATALOG],
            ),
        );
    }
}

final class CatalogProductRequestCapturingAdapter implements CatalogAdapterInterface
{
    public ?CatalogProductRequest $productRequest = null;

    public function search(CatalogSearchRequest $request, RequestContext $context): array
    {
        return [];
    }

    public function lookup(CatalogLookupRequest $request, RequestContext $context): array
    {
        return [];
    }

    public function getProduct(CatalogProductRequest $request, RequestContext $context): Product
    {
        $this->productRequest = $request;

        return new Product($request->id, 'Product Detail', 12.0);
    }
}
