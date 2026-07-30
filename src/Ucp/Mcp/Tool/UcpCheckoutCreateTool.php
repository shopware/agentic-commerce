<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-checkout-create', title: 'UCP Checkout Create', description: 'Create a checkout session through the shared UCP checkout capability. The payload parameter is a JSON object string matching the UCP checkout.create request.')]
/** @internal */
#[Package('checkout')]
final class UcpCheckoutCreateTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $payload = '{}'): string
    {
        try {
            $requestPayload = $this->toolContext->decodeObject($payload);

            return $this->toolContext->executeMutating(
                'checkout.create',
                $requestPayload,
                fn (RequestContext $context) => $this->operationExecutor->execute(new ShoppingOperationRequest(
                    'checkout.create',
                    $requestPayload,
                    $context,
                )),
            );
        } catch (\Throwable $exception) {
            return $this->toolContext->failure($exception);
        }
    }
}
