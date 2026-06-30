<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\Ucp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the UCP checkout *session* lifecycle (create / get / update) through the booted kernel
 * against a seeded storefront product, asserting the session assembles to `ready_for_complete`.
 *
 * Completion itself stays in the shell smoke on purpose: a completed-checkout response must satisfy
 * the SDK's generated schema for a full order (`totals`, `links`, currency, …), the secured order
 * read needs the persisted Shopware context token, and completion emits an outbound signed webhook
 * captured by an external server — all deployed-stack concerns a booted kernel cannot reproduce.
 * See {@see UcpFlowTestBehaviour} for the request-context setup.
 *
 * @internal
 */
final class UcpCheckoutFlowTest extends TestCase
{
    use UcpFlowTestBehaviour;

    #[Test]
    public function testCheckoutSessionCreateGetAndUpdateReachReadyForComplete(): void
    {
        $this->configureUcpRuntime();
        $productId = $this->seedStorefrontProduct('Kernel Test Album');
        $lineItem = static fn (int $quantity): array => ['item' => ['id' => $productId, 'title' => 'Kernel Test Album', 'price' => 19.99], 'quantity' => $quantity];
        $buyer = ['email' => 'kernel-tester@example.com', 'first_name' => 'Kernel', 'last_name' => 'Tester'];
        $fulfillment = ['type' => 'shipping', 'extra' => ['shipping_address' => ['street' => 'Kernel Street 1', 'zipcode' => '12345', 'city' => 'Berlin', 'country_code' => 'DE']]];

        $create = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions', ['line_items' => [$lineItem(1)], 'buyer' => $buyer, 'fulfillment' => $fulfillment]);
        self::assertSame(Response::HTTP_CREATED, $create->getStatusCode());
        $checkout = $this->decode($create);
        self::assertSame('ready_for_complete', $checkout['status'], 'Expected checkout.create to produce a ready-for-complete session.');
        $checkoutId = $checkout['id'];
        self::assertNotEmpty($checkoutId);

        $get = $this->ucpRequest('GET', '/ucp/v1/checkout-sessions/'.$checkoutId);
        self::assertSame(Response::HTTP_OK, $get->getStatusCode());
        self::assertSame($checkoutId, $this->decode($get)['id']);

        $update = $this->ucpRequest('PATCH', '/ucp/v1/checkout-sessions/'.$checkoutId, [
            'id' => $checkoutId,
            'line_items' => [$lineItem(2)],
            'buyer' => $buyer,
            'fulfillment' => $fulfillment,
        ]);
        self::assertSame(Response::HTTP_OK, $update->getStatusCode());
        self::assertSame(2, $this->decode($update)['line_items'][0]['quantity'], 'Expected checkout.update to change the line-item quantity.');
    }
}
