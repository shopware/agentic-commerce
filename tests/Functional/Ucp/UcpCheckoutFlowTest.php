<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Test\TestDefaults;
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

    private const INVOICE_HANDLER_ID = 'com.shopware.invoice';

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

        $complete = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions/'.$checkoutId.'/complete', ['id' => $checkoutId, 'payment' => ['instruments' => [['id' => 'instrument-1', 'type' => 'invoice', 'handler_id' => self::INVOICE_HANDLER_ID]]]]);
        self::assertSame(Response::HTTP_OK, $complete->getStatusCode());
        $completed = $this->decode($complete);
        self::assertSame('completed', $completed['status'], 'Expected checkout.complete to complete the session.');
        $orderId = $completed['order']['id'] ?? null;
        self::assertNotEmpty($orderId, 'Expected checkout.complete to create a Shopware order.');

        $completeAgain = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions/'.$checkoutId.'/complete', ['id' => $checkoutId, 'payment' => ['instruments' => [['id' => 'instrument-1', 'type' => 'invoice', 'handler_id' => self::INVOICE_HANDLER_ID]]]]);
        self::assertSame(Response::HTTP_OK, $completeAgain->getStatusCode(), (string) $completeAgain->getContent());
        $replayed = $this->decode($completeAgain);
        self::assertSame('completed', $replayed['status'], 'Expected a second checkout.complete to replay the completed session.');
        self::assertSame($orderId, $replayed['order']['id'] ?? null, 'Expected a second checkout.complete to place no second order.');

        $completedGet = $this->ucpRequest('GET', '/ucp/v1/checkout-sessions/'.$checkoutId);
        self::assertSame(Response::HTTP_OK, $completedGet->getStatusCode(), (string) $completedGet->getContent());
        $read = $this->decode($completedGet);
        self::assertSame('completed', $read['status'], 'Expected checkout.get to keep the paid session completed.');
        self::assertSame($orderId, $read['order']['id'] ?? null);

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
        self::assertSame('cancellation', $this->decode($cancelledOrder)['adjustments'][0]['type'] ?? null, 'Expected order.read to reflect the merchant cancellation.');
    }

    #[Test]
    public function testCompletionWithoutAnInstrumentIsRefusedWithoutPlacingAnOrder(): void
    {
        $this->assertCompletionIsRefusedWithoutPlacingAnOrder([]);
    }

    #[Test]
    public function testCompletionWithAHandlerTheProfileDoesNotAdvertiseIsRefusedWithoutPlacingAnOrder(): void
    {
        $this->assertCompletionIsRefusedWithoutPlacingAnOrder(['instruments' => [['id' => 'instrument-1', 'type' => 'invoice', 'handler_id' => 'com.example.deactivated']]]);
    }

    /**
     * Called by two tests rather than fed by a data provider, because the 6.5 lanes run this suite
     * on PHPUnit 9, which ignores the `DataProvider` attribute.
     *
     * @param array<string, mixed> $payment
     */
    private function assertCompletionIsRefusedWithoutPlacingAnOrder(array $payment): void
    {
        $this->configureUcpRuntime();
        $productId = $this->seedStorefrontProduct('Refused Album', 'UCP-IT-REFUSED');
        $buyerEmail = 'refused-completion@example.com';
        $fulfillment = ['type' => 'shipping', 'extra' => ['shipping_address' => ['street' => 'Kernel Street 1', 'zipcode' => '12345', 'city' => 'Berlin', 'country_code' => 'DE']]];

        $create = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions', [
            'line_items' => [['item' => ['id' => $productId, 'title' => 'Refused Album', 'price' => 19.99], 'quantity' => 1]],
            'buyer' => ['email' => $buyerEmail, 'first_name' => 'Refused', 'last_name' => 'Completion'],
            'fulfillment' => $fulfillment,
        ]);
        self::assertSame(Response::HTTP_CREATED, $create->getStatusCode(), (string) $create->getContent());
        $checkoutId = $this->decode($create)['id'];

        $complete = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions/'.$checkoutId.'/complete', ['id' => $checkoutId, 'payment' => $payment]);

        self::assertSame(Response::HTTP_OK, $complete->getStatusCode(), (string) $complete->getContent());
        $refused = $this->decode($complete);
        self::assertSame('incomplete', $refused['status']);
        self::assertArrayNotHasKey('order', $refused);
        $paymentFailedMessages = array_values(array_filter($refused['messages'] ?? [], static fn (array $message): bool => 'payment_failed' === ($message['code'] ?? null)));
        self::assertCount(1, $paymentFailedMessages);
        self::assertSame('error', $paymentFailedMessages[0]['type']);
        self::assertSame('recoverable', $paymentFailedMessages[0]['severity']);

        $connection = static::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM order_customer WHERE email = :email', ['email' => $buyerEmail]), 'Expected the refused completion to place no order.');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM customer WHERE email = :email', ['email' => $buyerEmail]), 'Expected the refused completion to register no guest customer.');
    }

    #[Test]
    public function testCheckoutCarriesTheSalesChannelLegalPagesAsLinks(): void
    {
        $this->configureUcpRuntime();
        $productId = $this->seedStorefrontProduct('Legal Links Album', 'UCP-IT-LEGAL');
        $lineItem = static fn (int $quantity): array => ['item' => ['id' => $productId, 'title' => 'Legal Links Album', 'price' => 19.99], 'quantity' => $quantity];
        $buyer = ['email' => 'legal-links@example.com', 'first_name' => 'Legal', 'last_name' => 'Links'];
        $fulfillment = ['type' => 'shipping', 'extra' => ['shipping_address' => ['street' => 'Kernel Street 1', 'zipcode' => '12345', 'city' => 'Berlin', 'country_code' => 'DE']]];

        $tosPage = $this->createCmsPage('UCP terms of service');
        $privacyPage = $this->createCmsPage('UCP privacy policy');
        $refundPage = $this->createCmsPage('UCP refund policy');
        $otherChannelShippingPage = $this->createCmsPage('Other channel shipping policy');

        $otherSalesChannelId = TestDefaults::SALES_CHANNEL;
        self::assertNotSame($this->ucpSalesChannelId, $otherSalesChannelId);

        $config = static::getContainer()->get(SystemConfigService::class);
        $config->set('core.basicInformation.tosPage', $tosPage, $this->ucpSalesChannelId);
        $config->set('core.basicInformation.privacyPage', $privacyPage, $this->ucpSalesChannelId);
        $config->set('core.basicInformation.revocationPage', $refundPage, $this->ucpSalesChannelId);
        $config->set('core.basicInformation.shippingPaymentInfoPage', null, $this->ucpSalesChannelId);
        $config->set('core.basicInformation.shippingPaymentInfoPage', null);
        $config->set('core.basicInformation.shippingPaymentInfoPage', $otherChannelShippingPage, $otherSalesChannelId);

        $cmsPath = null !== static::getContainer()->get('router')->getRouteCollection()->get('frontend.cms.page.full')
            ? '/page/cms/'
            : '/widgets/cms/';
        $expectedLegalLinks = [
            ['type' => 'terms_of_service', 'url' => $this->ucpDomain.$cmsPath.$tosPage],
            ['type' => 'privacy_policy', 'url' => $this->ucpDomain.$cmsPath.$privacyPage],
            ['type' => 'refund_policy', 'url' => $this->ucpDomain.$cmsPath.$refundPage],
        ];

        $create = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions', ['line_items' => [$lineItem(1)], 'buyer' => $buyer, 'fulfillment' => $fulfillment]);
        self::assertSame(Response::HTTP_CREATED, $create->getStatusCode(), (string) $create->getContent());
        $checkout = $this->decode($create);
        $checkoutId = $checkout['id'];
        $this->assertLegalLinks('continue', $expectedLegalLinks, $checkout);

        $get = $this->ucpRequest('GET', '/ucp/v1/checkout-sessions/'.$checkoutId);
        self::assertSame(Response::HTTP_OK, $get->getStatusCode(), (string) $get->getContent());
        $this->assertLegalLinks('continue', $expectedLegalLinks, $this->decode($get));

        $update = $this->ucpRequest('PATCH', '/ucp/v1/checkout-sessions/'.$checkoutId, [
            'id' => $checkoutId,
            'line_items' => [$lineItem(2)],
            'buyer' => $buyer,
            'fulfillment' => $fulfillment,
        ]);
        self::assertSame(Response::HTTP_OK, $update->getStatusCode(), (string) $update->getContent());
        $this->assertLegalLinks('continue', $expectedLegalLinks, $this->decode($update));

        $complete = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions/'.$checkoutId.'/complete', ['id' => $checkoutId, 'payment' => ['instruments' => [['id' => 'instrument-1', 'type' => 'invoice', 'handler_id' => self::INVOICE_HANDLER_ID]]]]);
        self::assertSame(Response::HTTP_OK, $complete->getStatusCode(), (string) $complete->getContent());
        $completed = $this->decode($complete);
        self::assertSame('completed', $completed['status']);
        $this->assertLegalLinks('order', $expectedLegalLinks, $completed);

        $completedGet = $this->ucpRequest('GET', '/ucp/v1/checkout-sessions/'.$checkoutId);
        self::assertSame(Response::HTTP_OK, $completedGet->getStatusCode(), (string) $completedGet->getContent());
        $this->assertLegalLinks('order', $expectedLegalLinks, $this->decode($completedGet));
    }

    /**
     * @param list<array<string, string>> $expectedLegalLinks
     * @param array<string, mixed>        $checkout
     */
    private function assertLegalLinks(string $leadingLinkType, array $expectedLegalLinks, array $checkout): void
    {
        $links = $checkout['links'] ?? [];
        self::assertIsArray($links);
        self::assertSame($leadingLinkType, $links[0]['type'] ?? null, 'Expected the session link to stay first.');
        self::assertSame($expectedLegalLinks, \array_slice($links, 1));

        foreach (\array_slice($links, 1) as $link) {
            self::assertStringStartsWith($this->ucpDomain.'/', $link['url']);
        }
        self::assertNotContains('shipping_policy', array_column($links, 'type'), 'Another sales channel\'s shipping page must not leak into this one.');
    }

    private function createCmsPage(string $name): string
    {
        $id = Uuid::randomHex();
        static::getContainer()->get('cms_page.repository')->create([
            ['id' => $id, 'type' => 'page', 'name' => $name],
        ], Context::createDefaultContext());

        return $id;
    }
}
