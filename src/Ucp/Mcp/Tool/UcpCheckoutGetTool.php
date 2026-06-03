<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;

#[McpTool(name: 'shopware-ucp-checkout-get', title: 'UCP Checkout Get', description: 'Load a checkout session by id through the shared UCP checkout capability.')]
#[Package('checkout')]
final readonly class UcpCheckoutGetTool
{
    public function __construct(
        private CheckoutCapabilityInterface $checkoutCapability,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id): string
    {
        return $this->toolContext->success(
            $this->checkoutCapability->getCheckout($id, $this->toolContext->requestContext())->toArray(),
        );
    }
}
