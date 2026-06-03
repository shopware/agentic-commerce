<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;

#[McpTool(name: 'shopware-ucp-checkout-create', title: 'UCP Checkout Create', description: 'Create a checkout session through the shared UCP checkout capability. The payload parameter is a JSON object matching the UCP checkout.create request.')]
#[Package('checkout')]
final readonly class UcpCheckoutCreateTool
{
    public function __construct(
        private CheckoutCapabilityInterface $checkoutCapability,
        private HttpPayloadMapper $payloadMapper,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $payload = '{}'): string
    {
        $checkout = $this->checkoutCapability->createCheckout(
            $this->payloadMapper->toCheckoutCreateRequest($this->toolContext->decodeObject($payload)),
            $this->toolContext->requestContext(),
        );

        return $this->toolContext->success($checkout->toArray());
    }
}
