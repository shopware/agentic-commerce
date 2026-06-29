<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-discount-apply', title: 'UCP Discount Apply', description: 'Apply a discount code to a cart through the shared UCP discount capability.')]
/** @internal */
#[Package('checkout')]
final class UcpDiscountApplyTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $cartId, string $code): string
    {
        try {
            $requestPayload = ['cart_id' => $cartId, 'code' => $code];

            return $this->toolContext->executeMutating(
                'discount.apply',
                $requestPayload,
                fn (RequestContext $context): array => $this->operationExecutor->execute(new ShoppingOperationRequest(
                    'discount.apply',
                    $requestPayload,
                    $context,
                )),
            );
        } catch (\Throwable $exception) {
            throw $this->toolContext->toToolCallException($exception);
        }
    }
}
