<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutGuestAddressPayloadResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Ucp\Sdk\Model\Checkout\FulfillmentSelection;

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
