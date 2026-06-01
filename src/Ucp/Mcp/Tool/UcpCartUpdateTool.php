<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;

#[McpTool(name: 'shopware-ucp-cart-update', title: 'UCP Cart Update', description: 'Replace cart line items through the shared UCP cart capability. The payload parameter is a JSON object matching the UCP cart.update request.')]
#[Package('checkout')]
final readonly class UcpCartUpdateTool
{
    public function __construct(
        private CartCapabilityInterface $cartCapability,
        private HttpPayloadMapper $payloadMapper,
        private UcpMcpToolContext $toolContext,
    ) {
    }

    public function __invoke(string $id, string $payload = '{}'): string
    {
        $cart = $this->cartCapability->updateCart(
            $this->payloadMapper->toCartUpdateRequest($id, $this->toolContext->decodeObject($payload)),
            $this->toolContext->requestContext(),
        );

        return $this->toolContext->success($cart->toArray());
    }
}
