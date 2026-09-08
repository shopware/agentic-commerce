<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-checkout-get', title: 'UCP Checkout Get', description: 'Load a checkout session by id through the shared UCP checkout capability.')]
/** @internal */
#[Package('checkout')]
final class UcpCheckoutGetTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id): string
    {
        try {
            return $this->toolContext->success($this->operationExecutor->execute(new ShoppingOperationRequest(
                'checkout.get',
                [],
                $this->toolContext->requestContext(),
                $id,
            )));
        } catch (\Throwable $exception) {
            return $this->toolContext->failure($exception);
        }
    }
}
