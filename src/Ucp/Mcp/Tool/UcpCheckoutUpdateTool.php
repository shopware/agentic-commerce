<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationToolSchemas;

#[McpTool(name: 'shopware-ucp-checkout-update', title: 'UCP Checkout Update', description: 'Update a checkout session through the shared UCP checkout capability. Pass payload as a UCP checkout.update request object.')]
#[Package('checkout')]
final readonly class UcpCheckoutUpdateTool
{
    public function __construct(
        private ShoppingOperationExecutor $operationExecutor,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Schema(definition: ShoppingOperationToolSchemas::CHECKOUT_UPDATE_INPUT)]
    public function __invoke(string $id, array $payload): string
    {
        try {
            return $this->toolContext->success($this->operationExecutor->execute(new ShoppingOperationRequest(
                'checkout.update',
                $payload,
                $this->toolContext->requestContext(),
                $id,
            )));
        } catch (\Throwable $exception) {
            throw $this->toolContext->toToolCallException($exception);
        }
    }
}
