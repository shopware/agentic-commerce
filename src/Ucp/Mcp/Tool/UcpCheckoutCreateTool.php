<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-checkout-create', title: 'UCP Checkout Create', description: 'Create a checkout session through the shared UCP checkout capability. The payload parameter is a JSON object string matching the UCP checkout.create request. "line_items" is always required, even when empty. To convert an existing cart into a checkout send "cart_id" together with "line_items": [] and the cart is reused as-is; send line_items to start from scratch instead. "discounts": {"codes": [...]}, "fulfillment" and "buyer_consent" are also accepted even though the published request schema omits them. Always use dryRun=true (the default) to validate the request without persisting it, then set dryRun=false to commit.')]
/** @internal */
#[Package('checkout')]
final class UcpCheckoutCreateTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $payload = '{}', bool $dryRun = true): string
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
                $dryRun,
            );
        } catch (\Throwable $exception) {
            return $this->toolContext->failure($exception);
        }
    }
}
