<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-cart-create', title: 'UCP Cart Create', description: 'Create a cart through the shared UCP cart capability. The payload parameter is a JSON object string matching the UCP cart.create request. Always use dryRun=true (the default) to validate the request without persisting it, then set dryRun=false to commit.')]
/** @internal */
#[Package('checkout')]
final class UcpCartCreateTool
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
                'cart.create',
                $requestPayload,
                fn (RequestContext $context) => $this->operationExecutor->execute(new ShoppingOperationRequest(
                    'cart.create',
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
