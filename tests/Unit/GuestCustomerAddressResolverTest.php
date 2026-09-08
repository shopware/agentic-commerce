<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\Country\SalesChannel\CountryRouteResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerAddressResolver;
use Ucp\Sdk\Exception\ValidationException;

/** @internal */
#[CoversClass(GuestCustomerAddressResolver::class)]
final class GuestCustomerAddressResolverTest extends TestCase
{
    #[Test]
    public function testItResolvesCountryCodeThroughStoreApiCountryRoute(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $country = new CountryEntity();
        $country->setId('11111111111111111111111111111111');

        $countryRoute = $this->createMock(AbstractCountryRoute::class);
        $countryRoute->expects(static::once())
            ->method('load')
            ->willReturnCallback(static function (
                \Symfony\Component\HttpFoundation\Request $_request,
                Criteria $criteria,
                SalesChannelContext $routeContext,
            ) use ($context, $country): CountryRouteResponse {
                self::assertSame($context, $routeContext);
                self::assertCount(1, $criteria->getFilters());

                $countries = new CountryCollection([$country]);

                return new CountryRouteResponse(new EntitySearchResult(
                    'country',
                    $countries->count(),
                    $countries,
                    null,
                    $criteria,
                    Context::createDefaultContext(),
                ));
            });

        $payload = (new GuestCustomerAddressResolver($countryRoute))->resolve($context, [
            'street' => 'Main Street 1',
            'zipcode' => '12345',
            'city' => 'Example City',
            'countryCode' => 'de',
        ]);

        self::assertSame([
            'street' => 'Main Street 1',
            'zipcode' => '12345',
            'city' => 'Example City',
            'countryId' => '11111111111111111111111111111111',
        ], $payload);
    }

    #[Test]
    public function testItNamesAPropertyTheAgentCanActuallySetWhenNoAddressWasStored(): void
    {
        $resolver = new GuestCustomerAddressResolver($this->createMock(AbstractCountryRoute::class));

        try {
            $resolver->resolve($this->createMock(SalesChannelContext::class), null);
            self::fail('Completing without a stored address must fail.');
        } catch (ValidationException $exception) {
            // These paths used to read `$.checkout_session.fulfillment.shipping_address`,
            // which is not a property of checkout.create, checkout.update or
            // checkout.complete in any UCP version — so the one message that says what is
            // missing pointed at a field an agent had no way to fill.
            self::assertSame(['$.fulfillment.methods[0].destinations[0].street_address is required'], $exception->getViolations());
            self::assertStringContainsString('fulfillment.methods[].destinations[]', $exception->getMessage());
        }
    }

    #[Test]
    public function testItNamesEveryIncompleteFieldOfTheStoredAddress(): void
    {
        $resolver = new GuestCustomerAddressResolver($this->createMock(AbstractCountryRoute::class));

        try {
            $resolver->resolve($this->createMock(SalesChannelContext::class), ['street' => 'Main Street 1']);
            self::fail('Completing with an incomplete address must fail.');
        } catch (ValidationException $exception) {
            self::assertSame([
                '$.fulfillment.methods[0].destinations[0].postal_code',
                '$.fulfillment.methods[0].destinations[0].address_locality',
                '$.fulfillment.methods[0].destinations[0].address_country',
            ], $exception->getViolations());
        }
    }
}
