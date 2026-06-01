<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;

#[McpTool(name: 'shopware-ucp-checkout-complete', title: 'UCP Checkout Complete', description: 'Complete a checkout session through the shared UCP checkout capability.')]
#[Package('checkout')]
final readonly class UcpCheckoutCompleteTool
{
    public function __construct(
        private CheckoutCapabilityInterface $checkoutCapability,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id): string
    {
        return $this->toolContext->success(
            $this->checkoutCapability->completeCheckout($id, $this->toolContext->requestContext())->toArray(),
        );
    }
}
