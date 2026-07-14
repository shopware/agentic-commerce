<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-checkout-complete', title: 'UCP Checkout Complete', description: 'Complete a checkout session through the shared UCP checkout capability.')]
/** @internal */
#[Package('checkout')]
final class UcpCheckoutCompleteTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    /**
     * @param array<string, mixed> $payload Optional checkout.complete payload, for example payment instruments and ap2.checkout_mandate.
     */
    public function __invoke(string $id, array $payload = []): string
    {
        try {
            return $this->toolContext->executeMutating(
                'checkout.complete',
                ['id' => $id, 'payload' => $payload],
                fn (RequestContext $context) => $this->operationExecutor->execute(new ShoppingOperationRequest(
                    'checkout.complete',
                    $payload,
                    $context,
                    $id,
                )),
            );
        } catch (\Throwable $exception) {
            throw $this->toolContext->toToolCallException($exception);
        }
    }
}
