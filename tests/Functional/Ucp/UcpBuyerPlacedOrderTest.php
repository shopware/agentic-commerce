<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the buyer side of the checkout handoff through the booted kernel: the agent creates a
 * checkout session, the buyer adopts its context token over the Store API, registers as a guest
 * (which rotates the token) and places the order — the way a storefront behind `continue_url`
 * does. The checkout must then read `completed` without `checkout.complete` ever being called.
 *
 * @internal
 */
final class UcpBuyerPlacedOrderTest extends TestCase
{
    use UcpFlowTestBehaviour;

    #[Test]
    public function testABuyerPlacedOrderCompletesTheCheckout(): void
    {
        $this->configureUcpRuntime();
        $productId = $this->seedStorefrontProduct('Buyer Placed Album', 'UCP-IT-BUYER');

        $create = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions', [
            'line_items' => [['item' => ['id' => $productId, 'title' => 'Buyer Placed Album', 'price' => 19.99], 'quantity' => 1]],
            'buyer' => ['email' => 'agent-buyer@example.com', 'first_name' => 'Agent', 'last_name' => 'Buyer'],
            'fulfillment' => ['type' => 'shipping', 'extra' => ['shipping_address' => ['street' => 'Kernel Street 1', 'zipcode' => '12345', 'city' => 'Berlin', 'country_code' => 'DE']]],
        ]);
        self::assertSame(Response::HTTP_CREATED, $create->getStatusCode(), (string) $create->getContent());
        $checkoutId = $this->decode($create)['id'];
        self::assertNotEmpty($checkoutId);

        $buyerToken = $this->registerGuest($checkoutId);
        self::assertNotSame($checkoutId, $buyerToken, 'Guest registration must rotate the context token.');

        $order = $this->storeApiRequest('POST', '/store-api/checkout/order', [], $buyerToken);
        self::assertSame(Response::HTTP_OK, $order->getStatusCode(), (string) $order->getContent());
        $orderId = $this->decode($order)['id'];
        self::assertNotEmpty($orderId);

        self::assertSame(1, $this->completionRows($checkoutId), 'Expected the buyer-placed order to write the completion record.');

        $get = $this->ucpRequest('GET', '/ucp/v1/checkout-sessions/'.$checkoutId);
        self::assertSame(Response::HTTP_OK, $get->getStatusCode(), (string) $get->getContent());
        $checkout = $this->decode($get);
        self::assertSame('completed', $checkout['status'], 'Expected checkout.get to report the buyer-placed order as completed.');
        self::assertSame($orderId, $checkout['order']['id'] ?? null, 'Expected the completed checkout to carry the buyer\'s order.');
        self::assertNotEmpty($checkout['line_items'], 'Expected the completed checkout to carry the order line items.');

        $orderRead = $this->ucpRequest('GET', '/ucp/v1/orders/'.$orderId, null, [
            'HTTP_SW_CONTEXT_TOKEN' => $this->completedCheckoutContextToken($checkoutId),
        ]);
        self::assertSame(Response::HTTP_OK, $orderRead->getStatusCode(), (string) $orderRead->getContent());
        self::assertSame($orderId, $this->decode($orderRead)['id'], 'Expected the secured order read to return the buyer\'s order.');
    }

    #[Test]
    public function testAnAgentDrivenCompletionRecordsItsOwnOrder(): void
    {
        $this->configureUcpRuntime();
        $invoiceHandlerId = $this->advertiseInvoicePaymentHandler();
        $productId = $this->seedStorefrontProduct('Agent Completed Album', 'UCP-IT-AGENT');

        $create = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions', [
            'line_items' => [['item' => ['id' => $productId, 'title' => 'Agent Completed Album', 'price' => 19.99], 'quantity' => 1]],
            'buyer' => ['email' => 'agent-completion@example.com', 'first_name' => 'Agent', 'last_name' => 'Completion'],
            'fulfillment' => ['type' => 'shipping', 'extra' => ['shipping_address' => ['street' => 'Kernel Street 1', 'zipcode' => '12345', 'city' => 'Berlin', 'country_code' => 'DE']]],
        ]);
        self::assertSame(Response::HTTP_CREATED, $create->getStatusCode(), (string) $create->getContent());
        $checkoutId = $this->decode($create)['id'];

        $complete = $this->ucpRequest('POST', '/ucp/v1/checkout-sessions/'.$checkoutId.'/complete', [
            'id' => $checkoutId,
            'payment' => ['instruments' => [['id' => 'instrument-1', 'type' => 'invoice', 'handler_id' => $invoiceHandlerId]]],
        ]);
        self::assertSame(Response::HTTP_OK, $complete->getStatusCode(), (string) $complete->getContent());
        $completed = $this->decode($complete);
        self::assertSame('completed', $completed['status']);

        self::assertSame(1, $this->completionRows($checkoutId));
        // The record must carry the completer's own order: the checkout id is the record's
        // primary key, so a count alone cannot tell who wrote it.
        $storedOrderId = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT LOWER(HEX(order_id)) FROM swag_agentic_commerce_ucp_checkout_completion WHERE checkout_id = :checkoutId',
            ['checkoutId' => $checkoutId],
        );
        self::assertSame($completed['order']['id'] ?? null, $storedOrderId, 'Expected the completion record to carry the agent-completed order.');
    }

    /**
     * Registers a guest on the checkout's context token, as the storefront does after
     * `continue_url`, and returns the rotated token.
     */
    private function registerGuest(string $contextToken): string
    {
        $connection = static::getContainer()->get(Connection::class);
        $salutationId = $connection->fetchOne('SELECT LOWER(HEX(id)) FROM salutation LIMIT 1');
        $countryId = $connection->fetchOne(
            'SELECT LOWER(HEX(country_id)) FROM sales_channel WHERE id = UNHEX(:id)',
            ['id' => $this->ucpSalesChannelId],
        );

        $register = $this->storeApiRequest('POST', '/store-api/account/register', [
            'guest' => true,
            'salutationId' => $salutationId,
            'firstName' => 'Store',
            'lastName' => 'Buyer',
            'email' => 'buyer-placed-order@example.com',
            'acceptedDataProtection' => true,
            'storefrontUrl' => $this->ucpDomain,
            'billingAddress' => ['street' => 'Buyer Street 1', 'zipcode' => '12345', 'city' => 'Berlin', 'countryId' => $countryId],
        ], $contextToken);
        self::assertSame(Response::HTTP_OK, $register->getStatusCode(), (string) $register->getContent());

        $rotatedToken = $register->headers->get('sw-context-token');
        self::assertIsString($rotatedToken);

        return $rotatedToken;
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function storeApiRequest(string $method, string $path, ?array $json, string $contextToken): Response
    {
        $accessKey = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT access_key FROM sales_channel WHERE id = UNHEX(:id)',
            ['id' => $this->ucpSalesChannelId],
        );
        self::assertIsString($accessKey);

        $server = [
            'HTTP_SW_ACCESS_KEY' => $accessKey,
            'HTTP_SW_CONTEXT_TOKEN' => $contextToken,
        ];
        $body = null;
        if (null !== $json) {
            $server['CONTENT_TYPE'] = 'application/json';
            $body = json_encode($json, \JSON_THROW_ON_ERROR);
        }

        $this->browser->getCookieJar()->clear();
        $this->browser->request($method, $this->ucpDomain.$path, [], [], $server, $body);

        return $this->browser->getResponse();
    }

    private function completionRows(string $checkoutId): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM swag_agentic_commerce_ucp_checkout_completion WHERE checkout_id = :checkoutId',
            ['checkoutId' => $checkoutId],
        );
    }
}
