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
#[McpTool(name: 'get_checkout', title: 'UCP Checkout Get', description: 'Load a checkout session by id through the shared UCP checkout capability.')]
#[McpToolGroup(UcpMcpToolset::NAME)]
#[Package('checkout')]
final class UcpCheckoutGetTool
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
                'checkout.get',
                [],
                $this->toolContext->requestContext(),
                $id,
            )));
        } catch (\Throwable $exception) {
            return $this->toolContext->failure($exception);
        }
    }
}
