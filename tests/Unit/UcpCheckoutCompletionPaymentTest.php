<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCompletionPayment;

/**
 * Pins the payment object UCP requires on checkout.complete.
 *
 * `checkout.complete.request.json` declares `required: ["payment"]`, derived from
 * checkout.json's `ucp_request: {complete: "required"}`. Before this existed the
 * tool sent an empty payload, so every commit failed with `$.payment is required`
 * and no UCP order could be placed by an agent at all.
 *
 * @internal
 */
final class UcpCheckoutCompletionPaymentTest extends TestCase
{
    #[Test]
    public function testAnEmptyPayloadGainsAPaymentObjectSoTheSpecRequiredFieldIsPresent(): void
    {
        self::assertSame(
            ['payment' => ['instruments' => []]],
            (new UcpCheckoutCompletionPayment())->apply([]),
        );
    }

    #[Test]
    public function testThePaymentObjectIsAJsonObjectRatherThanAnEmptyArray(): void
    {
        $payment = (new UcpCheckoutCompletionPayment())->apply([])['payment'];

        // An empty PHP array would encode as `[]` and passes the SDK validator only
        // because GeneratedSchemaValidator treats it as an object. Carrying an
        // explicit key means the payload does not depend on that leniency.
        self::assertIsArray($payment);
        self::assertArrayHasKey('instruments', $payment);
    }

    #[Test]
    public function testAnExplicitPaymentIsPassedThroughUntouched(): void
    {
        // Deliberately one level flatter than a real instrument list: decodeObject()
        // returns UcpMcpNestedJsonObject, which does not model a list of objects.
        $payload = ['payment' => ['handler_id' => 'com.shopware.invoice']];

        self::assertSame($payload, (new UcpCheckoutCompletionPayment())->apply($payload));
    }

    #[Test]
    public function testADeliberatelyEmptyPaymentIsNotOverwritten(): void
    {
        // The agent said something about payment. Replacing it would hide whatever
        // it meant once the SDK threads payment into completeCheckout().
        self::assertSame(['payment' => []], (new UcpCheckoutCompletionPayment())->apply(['payment' => []]));
    }

    #[Test]
    public function testUnrelatedPayloadKeysSurvive(): void
    {
        $result = (new UcpCheckoutCompletionPayment())->apply(['signals' => ['dev.ucp.buyer_ip' => '203.0.113.1']]);

        self::assertSame(['dev.ucp.buyer_ip' => '203.0.113.1'], $result['signals']);
        self::assertArrayHasKey('payment', $result);
    }
}
