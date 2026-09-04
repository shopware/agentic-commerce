<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-cart-update', title: 'UCP Cart Update', description: 'Set or change the quantity of a line item, or add, remove, or replace the line items, in an existing cart through the shared UCP cart capability. Use this, NOT shopware-ucp-cart-get, for any change to the cart contents or line-item quantities. The payload parameter is a JSON object string matching the UCP cart.update request: it carries the complete "line_items" array; the cart id travels as the id parameter and is not repeated in the payload. line_items replaces the cart contents rather than patching them, so always resend every line you want to keep. Always use dryRun=true (the default) to validate the request without persisting it, then set dryRun=false to commit.')]
/** @internal */
#[Package('checkout')]
final class UcpCartUpdateTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id, string $payload = '{}', bool $dryRun = true): string
    {
        try {
            $requestPayload = $this->toolContext->decodeObject($payload);

            return $this->toolContext->executeMutating(
                'cart.update',
                ['id' => $id, 'payload' => $requestPayload],
                fn (RequestContext $context) => $this->operationExecutor->execute(new ShoppingOperationRequest(
                    'cart.update',
                    $requestPayload,
                    $context,
                    $id,
                )),
                $dryRun,
            );
        } catch (\Throwable $exception) {
            return $this->toolContext->failure($exception);
        }
    }
}
