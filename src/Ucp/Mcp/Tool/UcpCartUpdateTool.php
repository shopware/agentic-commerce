<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationToolSchemas;

#[McpTool(name: 'shopware-ucp-cart-update', title: 'UCP Cart Update', description: 'Replace cart line items through the shared UCP cart capability. Pass payload as a UCP cart.update request object.')]
#[Package('checkout')]
final readonly class UcpCartUpdateTool
{
    public function __construct(
        private ShoppingOperationExecutor $operationExecutor,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Schema(definition: ShoppingOperationToolSchemas::CART_UPDATE_INPUT)]
    public function __invoke(string $id, array $payload): string
    {
        try {
            return $this->toolContext->success($this->operationExecutor->execute(new ShoppingOperationRequest(
                'cart.update',
                $payload,
                $this->toolContext->requestContext(),
                $id,
            )));
        } catch (\Throwable $exception) {
            throw $this->toolContext->toToolCallException($exception);
        }
    }
}
