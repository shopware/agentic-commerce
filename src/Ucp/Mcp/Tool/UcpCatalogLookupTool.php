<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;

#[McpTool(name: 'shopware-ucp-catalog-lookup', title: 'UCP Catalog Lookup', description: 'Load products by id from the current Store API sales-channel catalog through the shared UCP catalog capability.')]
#[Package('checkout')]
final readonly class UcpCatalogLookupTool
{
    public function __construct(
        private CatalogCapabilityInterface $catalogCapability,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $ids = '[]'): string
    {
        $products = $this->catalogCapability->lookup(
            new CatalogLookupRequest($this->toolContext->decodeStringList($ids)),
            $this->toolContext->requestContext(),
        );

        return $this->toolContext->success([
            'items' => array_map(static fn ($product): array => $product->toArray(), $products),
        ]);
    }
}
