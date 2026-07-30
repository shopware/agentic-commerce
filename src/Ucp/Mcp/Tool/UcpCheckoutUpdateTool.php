<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-checkout-update', title: 'UCP Checkout Update', description: 'Update a checkout session through the shared UCP checkout capability. The payload parameter is a JSON object string matching the UCP checkout.update request.')]
/** @internal */
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
            $requestPayload = $this->toolContext->decodeObject($payload);

            return $this->toolContext->executeMutating(
                'checkout.update',
                ['id' => $id, 'payload' => $requestPayload],
                fn (RequestContext $context) => $this->operationExecutor->execute(new ShoppingOperationRequest(
                    'checkout.update',
                    $requestPayload,
                    $context,
                    $id,
                )),
            );
        } catch (\Throwable $exception) {
            return $this->toolContext->failure($exception);
        }
    }
}
