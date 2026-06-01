<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;

#[McpTool(name: 'shopware-ucp-cart-create', title: 'UCP Cart Create', description: 'Create a cart through the shared UCP cart capability. The payload parameter is a JSON object matching the UCP cart.create request.')]
#[Package('checkout')]
final readonly class UcpCartCreateTool
{
    public function __construct(
        private CartCapabilityInterface $cartCapability,
        private HttpPayloadMapper $payloadMapper,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $payload = '{}'): string
    {
        $cart = $this->cartCapability->createCart(
            $this->payloadMapper->toCartCreateRequest($this->toolContext->decodeObject($payload)),
            $this->toolContext->requestContext(),
        );

        return $this->toolContext->success($cart->toArray());
    }
}
