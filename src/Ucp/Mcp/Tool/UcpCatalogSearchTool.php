<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;

#[McpTool(name: 'shopware-ucp-catalog-search', title: 'UCP Catalog Search', description: 'Search the current Store API sales-channel catalog through the same UCP catalog capability used by REST, A2A, and embedded flows.')]
#[Package('checkout')]
final readonly class UcpCatalogSearchTool
{
    public function __construct(
        private CatalogCapabilityInterface $catalogCapability,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $query = '', int $limit = 10): string
    {
        $context = $this->toolContext->requestContext();
        $result = $this->catalogCapability->search(new CatalogSearchRequest($query, $limit), $context);

        return $this->toolContext->success($result->toArray());
    }
}
