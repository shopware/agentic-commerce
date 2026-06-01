<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Model\Checkout\DiscountCode;

#[McpTool(name: 'shopware-ucp-discount-apply', title: 'UCP Discount Apply', description: 'Apply a discount code to a cart through the shared UCP discount capability.')]
#[Package('checkout')]
final readonly class UcpDiscountApplyTool
{
    public function __construct(
        private DiscountCapabilityInterface $discountCapability,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $cartId, string $code): string
    {
        return $this->toolContext->success(
            $this->discountCapability->applyCartDiscount($cartId, new DiscountCode($code), $this->toolContext->requestContext())->toArray(),
        );
    }
}
