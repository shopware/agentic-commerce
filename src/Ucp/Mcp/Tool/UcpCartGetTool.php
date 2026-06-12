<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-cart-get', title: 'UCP Cart Get', description: 'Load a cart by id through the shared UCP cart capability.')]
#[Package('checkout')]
final class UcpCartGetTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id): string
    {
        try {
            return $this->toolContext->success($this->operationExecutor->execute(new ShoppingOperationRequest(
                'cart.get',
                [],
                $this->toolContext->requestContext(),
                $id,
            )));
        } catch (\Throwable $exception) {
            throw $this->toolContext->toToolCallException($exception);
        }
    }
}
