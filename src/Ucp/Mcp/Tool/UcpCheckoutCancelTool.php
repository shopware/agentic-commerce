<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-checkout-cancel', title: 'UCP Checkout Cancel', description: 'Cancel a checkout session through the shared UCP checkout capability.')]
/** @internal */
#[Package('checkout')]
final class UcpCheckoutCancelTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id): string
    {
        try {
            return $this->toolContext->executeMutating(
                'checkout.cancel',
                ['id' => $id],
                fn (RequestContext $context) => $this->operationExecutor->execute(new ShoppingOperationRequest(
                    'checkout.cancel',
                    [],
                    $context,
                    $id,
                )),
            );
        } catch (\Throwable $exception) {
            return $this->toolContext->failure($exception);
        }
    }
}
