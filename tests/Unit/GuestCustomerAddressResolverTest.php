<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

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

/** @internal */
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
}
