<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\CartCapabilityInterface;

#[McpTool(name: 'shopware-ucp-cart-cancel', title: 'UCP Cart Cancel', description: 'Cancel a cart through the shared UCP cart capability.')]
#[Package('checkout')]
final readonly class UcpCartCancelTool
{
    public function __construct(
        private CartCapabilityInterface $cartCapability,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id): string
    {
        return $this->toolContext->success(
            $this->cartCapability->cancelCart($id, $this->toolContext->requestContext())->toArray(),
        );
    }
}
