<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutGuestAddressPayloadResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\FulfillmentSelection;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;

/**
 * @internal
 */
#[CoversClass(CheckoutGuestAddressPayloadResolver::class)]
final class CheckoutGuestAddressPayloadResolverTest extends TestCase
{
    /**
     * The address as a conformant agent sends it, and the only destination shape the
     * schema accepts: an `id` plus the postal address inline, and no `name`.
     *
     * Measured against GeneratedSchemaValidator on checkout.update.request — the
     * destination item is a oneOf of shipping_destination (an allOf of postal_address
     * plus a REQUIRED `id`) and retail_location (requires `id` and `name`). So a bare
     * address matches neither branch, an object with `id` and `name` matches both, and
     * only `id` without `name` matches exactly one.
     */
    private const DESTINATION = [
        'id' => 'destination-1',
        'street_address' => 'Evaluation Street 1',
        'address_locality' => 'Berlin',
        'postal_code' => '10115',
        'address_country' => 'de',
        'first_name' => 'MCP',
        'last_name' => 'Evals',
    ];

    #[Test]
    public function testItReadsTheAddressOutOfAShippingDestination(): void
    {
        $address = $this->resolve(['methods' => [[
            'type' => 'shipping',
            'line_item_ids' => ['line-1'],
            'destinations' => [self::DESTINATION],
        ]]]);

        self::assertSame([
            'street' => 'Evaluation Street 1',
            'zipcode' => '10115',
            'city' => 'Berlin',
            'countryCode' => 'DE',
        ], $address);
    }

    #[Test]
    public function testItReadsTheAddressNestedUnderARetailLocation(): void
    {
        $address = $this->resolve(['methods' => [[
            'type' => 'pickup',
            'destinations' => [[
                'id' => 'store-1',
                'name' => 'Berlin Store',
                'address' => self::DESTINATION,
            ]],
        ]]]);

        self::assertSame('Evaluation Street 1', $address['street'] ?? null);
        self::assertSame('Berlin', $address['city'] ?? null);
    }

    #[Test]
    public function testItPrefersTheSelectedDestinationOverTheFirstOne(): void
    {
        $address = $this->resolve(['methods' => [[
            'selected_destination_id' => 'destination-2',
            'destinations' => [
                self::DESTINATION,
                ['id' => 'destination-2', 'street_address' => 'Second Street 2', 'address_locality' => 'Hamburg', 'postal_code' => '20095', 'address_country' => 'DE'],
            ],
        ]]]);

        self::assertSame('Second Street 2', $address['street'] ?? null);
        self::assertSame('Hamburg', $address['city'] ?? null);
    }

    #[Test]
    public function testItAppendsTheExtendedAddressToTheStreet(): void
    {
        $address = $this->resolve(['methods' => [[
            'destinations' => [['extended_address' => 'Apartment 4b'] + self::DESTINATION],
        ]]]);

        self::assertSame('Evaluation Street 1 Apartment 4b', $address['street'] ?? null);
    }

    #[Test]
    public function testItSkipsAMethodWhoseDestinationCarriesNoUsableAddress(): void
    {
        $address = $this->resolve(['methods' => [
            ['type' => 'pickup', 'destinations' => [['id' => 'locker-1', 'name' => 'Packstation 42']]],
            ['type' => 'shipping', 'destinations' => [self::DESTINATION]],
        ]]);

        self::assertSame('Evaluation Street 1', $address['street'] ?? null);
    }

    #[Test]
    public function testItStillAcceptsTheShopwareShapedShippingAddress(): void
    {
        // Not a UCP property, so nothing conformant sends it — kept so an agent built
        // against the previous behaviour keeps working.
        $address = $this->resolve(['shipping_address' => [
            'street' => 'Legacy Street 9',
            'zipcode' => '10115',
            'city' => 'Berlin',
            'country_code' => 'de',
        ]]);

        self::assertSame([
            'street' => 'Legacy Street 9',
            'zipcode' => '10115',
            'city' => 'Berlin',
            'countryCode' => 'DE',
        ], $address);
    }

    #[Test]
    public function testItRejectsAPartialDestinationInsteadOfDroppingIt(): void
    {
        // Reported from the other side in shopware/agentic-commerce#131: returning null
        // here made the plugin drop the address and refuse two steps later with a
        // message about a different field. `postal_address` marks nothing required, so
        // an incomplete one is schema-valid and only this layer can catch it.
        try {
            $this->resolve(['methods' => [[
                'destinations' => [['id' => 'destination-1', 'postal_code' => '10115']],
            ]]]);
            self::fail('A partial address must not be silently dropped.');
        } catch (ValidationException $exception) {
            self::assertSame([
                '$.fulfillment.methods[0].destinations[0].street_address is required',
                '$.fulfillment.methods[0].destinations[0].address_locality is required',
            ], $exception->getViolations());
            self::assertStringContainsString('street_address, postal_code and address_locality', $exception->getMessage());
        }
    }

    #[Test]
    public function testItNamesTheDestinationItActuallyReadRatherThanAssumingTheFirst(): void
    {
        try {
            $this->resolve(['methods' => [
                // Selection only, so this method resolves nothing and the walk continues.
                ['destinations' => [['id' => 'destination-0']]],
                [
                    'selected_destination_id' => 'destination-9',
                    'destinations' => [self::DESTINATION, ['id' => 'destination-9', 'address_locality' => 'Hamburg']],
                ],
            ]]);
            self::fail('A partial address must not be silently dropped.');
        } catch (ValidationException $exception) {
            // methods[1].destinations[1] — the one selected_destination_id points at.
            self::assertSame([
                '$.fulfillment.methods[1].destinations[1].street_address is required',
                '$.fulfillment.methods[1].destinations[1].postal_code is required',
            ], $exception->getViolations());
        }
    }

    #[Test]
    public function testItNamesTheNestedPathForARetailLocation(): void
    {
        try {
            $this->resolve(['methods' => [[
                'destinations' => [['id' => 'store-1', 'name' => 'Berlin Store', 'address' => ['postal_code' => '10115']]],
            ]]]);
            self::fail('A partial address must not be silently dropped.');
        } catch (ValidationException $exception) {
            self::assertSame([
                '$.fulfillment.methods[0].destinations[0].address.street_address is required',
                '$.fulfillment.methods[0].destinations[0].address.address_locality is required',
            ], $exception->getViolations());
        }
    }

    #[Test]
    public function testItTreatsAnyPostalFieldAsEvidenceOfAnAttemptedAddress(): void
    {
        // No mapped field at all, but address_region is unambiguously an address
        // attempt rather than a selection by id.
        $this->expectException(ValidationException::class);

        $this->resolve(['methods' => [[
            'destinations' => [['id' => 'destination-1', 'address_region' => 'Berlin']],
        ]]]);
    }

    #[Test]
    public function testADestinationCarryingOnlyAnIdIsASelectionNotABrokenAddress(): void
    {
        // `shipping_destination` requires `id` and nothing else, so selecting a
        // destination the business already offered looks exactly like this. Throwing
        // here would break that pattern; it must fall through to the stored address.
        self::assertNull($this->resolver()->resolve(new FulfillmentSelection('shipping', null, [
            'methods' => [['type' => 'shipping', 'line_item_ids' => ['line-1'], 'destinations' => [['id' => 'destination-1']]]],
        ])));
    }

    #[Test]
    public function testItRejectsAPartialLegacyShippingAddress(): void
    {
        try {
            $this->resolve(['shipping_address' => ['street' => 'Legacy Street 9']]);
            self::fail('A partial address must not be silently dropped.');
        } catch (ValidationException $exception) {
            self::assertSame([
                '$.fulfillment.shipping_address.zipcode is required',
                '$.fulfillment.shipping_address.city is required',
            ], $exception->getViolations());
            self::assertStringContainsString('fulfillment.methods[].destinations[]', $exception->getMessage());
        }
    }

    #[Test]
    public function testItReadsTheBillingAddressFromThePaymentInstrument(): void
    {
        // UCP has exactly two address slots and this is the second one:
        // payment.instruments[].billing_address. The plugin used to register the
        // FULFILLMENT address as Shopware's billing address, which is only right when
        // the two happen to be identical.
        $addresses = $this->resolver()->resolveAddresses(
            new FulfillmentSelection('shipping', null, ['methods' => [['destinations' => [self::DESTINATION]]]]),
            null,
            new PaymentInstrument('delegated', 'com.shopware.invoice', [], [
                'street_address' => 'Billing Street 2',
                'address_locality' => 'Hamburg',
                'postal_code' => '20095',
                'address_country' => 'DE',
            ]),
        );

        $billing = $addresses['billing'];
        $shipping = $addresses['shipping'];
        self::assertNotNull($billing);
        self::assertNotNull($shipping);

        self::assertSame('Billing Street 2', $billing['street']);
        self::assertSame('Hamburg', $billing['city']);
        self::assertSame('Evaluation Street 1', $shipping['street']);
        self::assertSame('Berlin', $shipping['city']);
    }

    #[Test]
    public function testABillingAddressAloneFillsBothForACartWithNothingToShip(): void
    {
        // A digital cart has no fulfillment destination, so the instrument's billing
        // address is the only address the protocol offers. Shopware still needs a
        // billing address to register a guest, and no shipping address is required.
        $addresses = $this->resolver()->resolveAddresses(null, null, new PaymentInstrument('delegated', 'com.shopware.invoice', [], [
            'street_address' => 'Billing Street 2',
            'address_locality' => 'Hamburg',
            'postal_code' => '20095',
            'address_country' => 'DE',
        ]));

        self::assertSame('Billing Street 2', $addresses['billing']['street'] ?? null);
        self::assertSame($addresses['billing'], $addresses['shipping']);
    }

    #[Test]
    public function testAShippingDestinationAloneStillFillsBoth(): void
    {
        // The behaviour every existing session relies on: one address, used for both.
        $addresses = $this->resolver()->resolveAddresses(
            new FulfillmentSelection('shipping', null, ['methods' => [['destinations' => [self::DESTINATION]]]]),
        );

        self::assertSame('Evaluation Street 1', $addresses['billing']['street'] ?? null);
        self::assertSame($addresses['billing'], $addresses['shipping']);
    }

    #[Test]
    public function testResolveStillReturnsTheBillingAddressForSingleAddressCallers(): void
    {
        $address = $this->resolver()->resolve(null, null, new PaymentInstrument('delegated', 'com.shopware.invoice', [], [
            'street_address' => 'Billing Street 2',
            'address_locality' => 'Hamburg',
            'postal_code' => '20095',
        ]));

        self::assertSame('Billing Street 2', $address['street'] ?? null);
    }

    #[Test]
    public function testItResolvesNothingWhenTheFulfillmentCarriesNoAddressAtAll(): void
    {
        self::assertNull($this->resolver()->resolve(new FulfillmentSelection('shipping', null, [
            'methods' => [['type' => 'shipping', 'line_item_ids' => ['line-1']]],
        ])));

        self::assertNull($this->resolver()->resolve(null));
    }

    /**
     * @param array<string, mixed> $fulfillment
     *
     * @return array<string, string>
     */
    private function resolve(array $fulfillment): array
    {
        $address = $this->resolver()->resolve(new FulfillmentSelection('shipping', null, $fulfillment));

        self::assertNotNull($address, 'The fulfillment payload carried an address that was not read.');

        return $address;
    }

    private function resolver(): CheckoutGuestAddressPayloadResolver
    {
        // The store is only consulted for a stored session, which none of these cases
        // has, so it never gets called.
        return new CheckoutGuestAddressPayloadResolver(
            new CheckoutSessionStore($this->createMock(SalesChannelContextPersister::class)),
        );
    }
}
