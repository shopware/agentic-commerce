<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'shopware-ucp-order-get', title: 'UCP Order Get', description: 'Load an order by id through the shared UCP order capability.')]
/** @internal */
#[Package('checkout')]
final class UcpOrderGetTool
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
                'order.get',
                [],
                $this->toolContext->requestContext(),
                $id,
            )));
        } catch (\Throwable $exception) {
            return $this->toolContext->failure($exception);
        }
    }
}
