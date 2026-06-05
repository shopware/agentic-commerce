<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-cart-create', title: 'UCP Cart Create', description: 'Create a cart through the shared UCP cart capability. The payload parameter is a JSON object string matching the UCP cart.create request.')]
#[Package('checkout')]
final readonly class UcpCartCreateTool
{
    public function __construct(
        private ShoppingOperationExecutor $operationExecutor,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $payload = '{}'): string
    {
        try {
            return $this->toolContext->success($this->operationExecutor->execute(new ShoppingOperationRequest(
                'cart.create',
                $this->toolContext->decodeObject($payload),
                $this->toolContext->requestContext(),
            )));
        } catch (\Throwable $exception) {
            throw $this->toolContext->toToolCallException($exception);
        }
    }
}
