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
 * the checkout capability. An agent that says nothing about payment therefore gets an
 * empty instrument list, which completion then refuses with `payment_failed`, rather
 * than a validation error it has no parameter to fix.
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

    /**
     * The `handler_id` of every instrument the completion would carry, read the way the SDK maps
     * `payment` into `CheckoutCompleteRequest`: the `instruments` list, or the flat single instrument.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public function handlerIds(array $payload): array
    {
        $payment = $payload['payment'] ?? null;
        if (!\is_array($payment)) {
            return [];
        }

        if (isset($payment['instruments'])) {
            $handlerIds = [];
            foreach (\is_array($payment['instruments']) ? $payment['instruments'] : [] as $instrument) {
                if (\is_array($instrument)) {
                    $handlerIds[] = self::handlerId($instrument);
                }
            }

            return $handlerIds;
        }

        return isset($payment['handler_id']) || isset($payment['type']) ? [self::handlerId($payment)] : [];
    }

    /**
     * @param array<mixed> $instrument
     */
    private static function handlerId(array $instrument): string
    {
        $handlerId = $instrument['handler_id'] ?? '';

        return \is_scalar($handlerId) ? (string) $handlerId : '';
    }
}
