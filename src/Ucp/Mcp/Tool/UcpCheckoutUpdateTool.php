<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;

#[McpTool(name: 'shopware-ucp-checkout-update', title: 'UCP Checkout Update', description: 'Update a checkout session through the shared UCP checkout capability. The payload parameter is a JSON object matching the UCP checkout.update request.')]
#[Package('checkout')]
final readonly class UcpCheckoutUpdateTool
{
    public function __construct(
        private CheckoutCapabilityInterface $checkoutCapability,
        private HttpPayloadMapper $payloadMapper,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id, string $payload = '{}'): string
    {
        $checkout = $this->checkoutCapability->updateCheckout(
            $this->payloadMapper->toCheckoutUpdateRequest($id, $this->toolContext->decodeObject($payload)),
            $this->toolContext->requestContext(),
        );

        return $this->toolContext->success($checkout->toArray());
    }
}
