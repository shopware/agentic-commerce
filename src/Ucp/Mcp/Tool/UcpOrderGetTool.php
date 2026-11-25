<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Mcp\Attribute\McpToolGroup;
use Shopware\Core\Framework\Mcp\Result\McpToolResult;
use Swag\AgenticCommerce\Ucp\Mcp\UcpMcpToolset;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

/** @internal */
#[McpTool(name: 'get_order', title: 'UCP Order Get', description: 'Load an order by id through the shared UCP order capability.')]
#[McpToolGroup(UcpMcpToolset::NAME)]
#[Package('after-sales')]
final class UcpOrderGetTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id): McpToolResult
    {
        try {
            return $this->toolContext->success($this->operationExecutor->execute(new ShoppingOperationRequest(
                'order.get',
                [],
                $this->toolContext->requestContext(),
                $id,
            )));
        } catch (\Throwable $exception) {
            return $this->toolContext->failure($exception);
        }
    }
}
