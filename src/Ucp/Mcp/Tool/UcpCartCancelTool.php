<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Mcp\Attribute\McpToolGroup;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationRequest;

#[McpTool(name: 'cancel_cart', title: 'UCP Cart Cancel', description: 'Cancel a cart through the shared UCP cart capability. Always use dryRun=true (the default) to validate the request without persisting it, then set dryRun=false to commit.')]
#[McpToolGroup('discovery')]
/** @internal */
#[Package('checkout')]
final class UcpCartCancelTool
{
    public function __construct(
        private readonly ShoppingOperationExecutor $operationExecutor,
        private readonly UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id, bool $dryRun = true): string
    {
        try {
            return $this->toolContext->executeMutating(
                'cart.cancel',
                ['id' => $id],
                fn (RequestContext $context) => $this->operationExecutor->execute(new ShoppingOperationRequest(
                    'cart.cancel',
                    [],
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
