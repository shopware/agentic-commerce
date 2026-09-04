<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-catalog-lookup', title: 'UCP Catalog Lookup', description: 'Load products by id from the current Store API sales-channel catalog through the shared UCP catalog capability. The ids parameter is a string, NOT an array: pass a JSON array string such as ["id-a","id-b"], or a single id, or a comma-separated list of ids.')]
/** @internal */
#[Package('checkout')]
final class UcpCatalogLookupTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
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
            return $this->toolContext->failure($exception);
        }
    }
}
