<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;

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
     * Repeats the SDK's mapping of `payment` into `CheckoutCompleteRequest`, because its mapper is `@internal`.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<PaymentInstrument>
     */
    public function instruments(array $payload): array
    {
        $payment = $payload['payment'] ?? null;
        if (!\is_array($payment)) {
            return [];
        }

        if (isset($payment['instruments'])) {
            $instruments = [];
            foreach (\is_array($payment['instruments']) ? $payment['instruments'] : [] as $instrument) {
                if (\is_array($instrument)) {
                    $instruments[] = self::instrument($instrument);
                }
            }

            return $instruments;
        }

        return isset($payment['handler_id']) || isset($payment['type']) ? [self::instrument($payment)] : [];
    }

    /**
     * @param array<mixed> $payload
     */
    private static function instrument(array $payload): PaymentInstrument
    {
        return new PaymentInstrument(
            self::scalarString($payload['type'] ?? 'tokenized'),
            self::scalarString($payload['handler_id'] ?? ''),
            self::arrayOrEmpty($payload['credential'] ?? null),
            self::arrayOrEmpty($payload['billing_address'] ?? null),
            true === ($payload['selected'] ?? false),
        );
    }

    private static function scalarString(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayOrEmpty(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}
