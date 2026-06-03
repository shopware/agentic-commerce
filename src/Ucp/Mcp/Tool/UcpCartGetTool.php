<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\CartCapabilityInterface;

#[McpTool(name: 'shopware-ucp-cart-get', title: 'UCP Cart Get', description: 'Load a cart by id through the shared UCP cart capability.')]
#[Package('checkout')]
final readonly class UcpCartGetTool
{
    public function __construct(
        private CartCapabilityInterface $cartCapability,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id): string
    {
        return $this->toolContext->success(
            $this->cartCapability->getCart($id, $this->toolContext->requestContext())->toArray(),
        );
    }
}
