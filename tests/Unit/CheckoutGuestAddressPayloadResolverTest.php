<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutGuestAddressPayloadResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\FulfillmentSelection;

/**
 * @internal
 */
#[CoversClass(CheckoutGuestAddressPayloadResolver::class)]
final class CheckoutGuestAddressPayloadResolverTest extends TestCase
{
    #[Test]
    public function testAcceptsShopShapeShippingAddress(): void
    {
        $fulfillment = new FulfillmentSelection('shipping', null, [
            'shipping_address' => ['street' => 'Teststr. 1', 'zipcode' => '12345', 'city' => 'Berlin', 'country_code' => 'de'],
        ]);

        self::assertSame(
            ['street' => 'Teststr. 1', 'zipcode' => '12345', 'city' => 'Berlin', 'countryCode' => 'DE'],
            $this->resolver()->resolve($fulfillment),
        );
    }

    #[Test]
    public function testAcceptsBillingAddressWithStandardFieldAliases(): void
    {
        $fulfillment = new FulfillmentSelection('digital', null, [
            'billing_address' => ['line1' => 'Teststr. 1', 'postal_code' => '12345', 'locality' => 'Berlin', 'country' => 'DE'],
        ]);

        self::assertSame(
            ['street' => 'Teststr. 1', 'zipcode' => '12345', 'city' => 'Berlin', 'countryCode' => 'DE'],
            $this->resolver()->resolve($fulfillment),
        );
    }

    #[Test]
    public function testAcceptsCanonicalUcpPostalAddressFieldNames(): void
    {
        $fulfillment = new FulfillmentSelection('shipping', null, [
            'shipping_address' => [
                'street_address' => 'Teststr. 1',
                'postal_code' => '12345',
                'address_locality' => 'Berlin',
                'country_code' => 'de',
            ],
        ]);

        self::assertSame(
            ['street' => 'Teststr. 1', 'zipcode' => '12345', 'city' => 'Berlin', 'countryCode' => 'DE'],
            $this->resolver()->resolve($fulfillment),
        );
    }

    #[Test]
    public function testThrowsOnMalformedAddressNamingMissingFields(): void
    {
        $fulfillment = new FulfillmentSelection('shipping', null, [
            'shipping_address' => ['street' => 'Teststr. 1'], // missing zipcode, city
        ]);

        try {
            $this->resolver()->resolve($fulfillment);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $violations = implode(' ', $e->getViolations());
            self::assertStringContainsStringIgnoringCase('zipcode', $violations);
            self::assertStringContainsStringIgnoringCase('city', $violations);
        }
    }

    #[Test]
    public function testReturnsNullWhenNoAddressObjectPresent(): void
    {
        $fulfillment = new FulfillmentSelection('shipping', 'method-1', ['available_methods' => []]);

        self::assertNull($this->resolver()->resolve($fulfillment));
    }

    private function resolver(): CheckoutGuestAddressPayloadResolver
    {
        // metadata is never passed in these tests, so the session store is unused.
        $store = (new \ReflectionClass(CheckoutSessionStore::class))->newInstanceWithoutConstructor();

        return new CheckoutGuestAddressPayloadResolver($store);
    }
}
