<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-catalog-lookup', title: 'UCP Catalog Lookup', description: 'Load products by id from the current Store API sales-channel catalog through the shared UCP catalog capability.')]
#[Package('checkout')]
final readonly class UcpCatalogLookupTool
{
    public function __construct(
        private ShoppingOperationExecutor $operationExecutor,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $ids = '[]'): string
    {
        try {
            return $this->toolContext->success($this->operationExecutor->execute(new ShoppingOperationRequest(
                'catalog.lookup',
                ['ids' => $this->toolContext->decodeStringList($ids)],
                $this->toolContext->requestContext(),
            )));
        } catch (\Throwable $exception) {
            throw $this->toolContext->toToolCallException($exception);
        }
    }
}
