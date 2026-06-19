<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-cart-update', title: 'UCP Cart Update', description: 'Replace cart line items through the shared UCP cart capability. The payload parameter is a JSON object string matching the UCP cart.update request.')]
#[Package('checkout')]
final class UcpCartUpdateTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id, string $payload = '{}'): string
    {
        try {
            $requestPayload = $this->toolContext->decodeObject($payload);

            return $this->toolContext->executeMutating(
                'cart.update',
                ['id' => $id, 'payload' => $requestPayload],
                fn (RequestContext $context): array => $this->operationExecutor->execute(new ShoppingOperationRequest(
                    'cart.update',
                    $requestPayload,
                    $context,
                    $id,
                )),
            );
        } catch (\Throwable $exception) {
            throw $this->toolContext->toToolCallException($exception);
        }
    }
}
