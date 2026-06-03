<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\OrderCapabilityInterface;

#[McpTool(name: 'shopware-ucp-order-get', title: 'UCP Order Get', description: 'Load an order by id through the shared UCP order capability.')]
#[Package('checkout')]
final readonly class UcpOrderGetTool
{
    public function __construct(
        private OrderCapabilityInterface $orderCapability,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id): string
    {
        return $this->toolContext->success(
            $this->orderCapability->getOrder($id, $this->toolContext->requestContext())->toArray(),
        );
    }
}
