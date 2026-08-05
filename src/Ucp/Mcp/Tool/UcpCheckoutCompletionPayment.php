<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Shopware\Core\Framework\Log\Package;

/**
 * Guarantees the spec-required payment object on a completion request.
 *
 * UCP marks `payment` as required for the complete operation — `checkout.json`
 * annotates it `ucp_request: {create: "optional", update: "optional",
 * complete: "required"}` — so a request without it is rejected before it reaches
 * the checkout capability. Nothing in Shopware reads the instrument yet:
 * CheckoutAdapterInterface::completeCheckout() takes only an id and a context, and
 * completion charges the sales channel default payment method. An agent that says
 * nothing about payment therefore gets an empty instrument list rather than a
 * validation error it has no parameter to fix.
 *
 * Split out of UcpCheckoutCompleteTool so this is testable: the tool depends on the
 * final ShoppingOperationExecutor and cannot be constructed with a mock.
 *
 * @phpstan-import-type UcpMcpNestedJsonObject from UcpMcpToolContext
 *
 * @internal
 */
#[Package('checkout')]
final class UcpCheckoutCompletionPayment
{
    /**
     * @param UcpMcpNestedJsonObject $payload
     *
     * @return UcpMcpNestedJsonObject
     */
    public function apply(array $payload): array
    {
        // An explicit payment is passed through untouched, including a deliberate
        // null or empty object. Once the SDK threads payment into
        // completeCheckout() it becomes meaningful, and silently overwriting what
        // the agent sent would hide that.
        if (\array_key_exists('payment', $payload)) {
            return $payload;
        }

        $payload['payment'] = ['instruments' => []];

        return $payload;
    }
}
