<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationToolSchemas;

#[McpTool(name: 'shopware-ucp-cart-create', title: 'UCP Cart Create', description: 'Create a cart through the shared UCP cart capability. Pass payload as a UCP cart.create request object.')]
#[Package('checkout')]
final readonly class UcpCartCreateTool
{
    public function __construct(
        private ShoppingOperationExecutor $operationExecutor,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Schema(definition: ShoppingOperationToolSchemas::CART_CREATE_INPUT)]
    public function __invoke(array $payload): string
    {
        try {
            return $this->toolContext->success($this->operationExecutor->execute(new ShoppingOperationRequest(
                'cart.create',
                $payload,
                $this->toolContext->requestContext(),
            )));
        } catch (\Throwable $exception) {
            throw $this->toolContext->toToolCallException($exception);
        }
    }
}
