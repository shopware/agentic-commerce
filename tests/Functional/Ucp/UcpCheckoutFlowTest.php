<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the UCP checkout capability end to end through the booted kernel against a seeded
 * storefront product: create / get / update the session, complete it into a real Shopware order,
 * and read that order back via its persisted Shopware context token.
 *
 * The only checkout concern left to the deployed-stack smoke is the outbound, signed order webhook:
 * the smoke verifies it is actually delivered to an external endpoint with `signature`,
 * `signature-input`, and `content-digest` headers — on-the-wire delivery a booted kernel can't
 * observe. See {@see UcpFlowTestBehaviour} for the request-context setup.
 *
 * @internal
 */
final class UcpCheckoutFlowTest extends TestCase
{
    use UcpFlowTestBehaviour;

    #[Test]
    public function testCheckoutCreateUpdateCompleteAndOrderRead(): void
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

        $complete = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions/'.$checkoutId.'/complete', ['id' => $checkoutId, 'payment' => (object) []]);
        self::assertSame(Response::HTTP_OK, $complete->getStatusCode());
        $completed = $this->decode($complete);
        self::assertSame('completed', $completed['status'], 'Expected checkout.complete to complete the session.');
        $orderId = $completed['order']['id'] ?? null;
        self::assertNotEmpty($orderId, 'Expected checkout.complete to create a Shopware order.');

        $order = $this->ucpRequest('GET', '/ucp/v1/orders/'.$orderId, null, [
            'HTTP_SW_CONTEXT_TOKEN' => $this->completedCheckoutContextToken($checkoutId),
        ]);
        self::assertSame(Response::HTTP_OK, $order->getStatusCode());
        self::assertSame($orderId, $this->decode($order)['id'], 'Expected the secured order read to return the created order.');

        static::getContainer()->get(StateMachineRegistry::class)->transition(
            new Transition('order', $orderId, StateMachineTransitionActions::ACTION_CANCEL, 'stateId'),
            Context::createDefaultContext(),
        );

        $cancelledOrder = $this->ucpRequest('GET', '/ucp/v1/orders/'.$orderId, null, [
            'HTTP_SW_CONTEXT_TOKEN' => $this->completedCheckoutContextToken($checkoutId),
        ]);
        self::assertSame(Response::HTTP_OK, $cancelledOrder->getStatusCode());
        self::assertSame('order_cancelled', $this->decode($cancelledOrder)['messages'][0]['code'] ?? null, 'Expected order.read to reflect the merchant cancellation.');
    }
}
