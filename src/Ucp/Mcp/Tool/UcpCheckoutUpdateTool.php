<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-checkout-update', title: 'UCP Checkout Update', description: 'Update a checkout session through the shared UCP checkout capability. The payload parameter is a JSON object string matching the UCP checkout.update request.')]
#[Package('checkout')]
final class UcpCheckoutUpdateTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id, string $payload = '{}'): string
    {
        try {
            return $this->toolContext->success($this->operationExecutor->execute(new ShoppingOperationRequest(
                'checkout.update',
                $this->toolContext->decodeObject($payload),
                $this->toolContext->requestContext(),
                $id,
            )));
        } catch (\Throwable $exception) {
            throw $this->toolContext->toToolCallException($exception);
        }
    }
}
