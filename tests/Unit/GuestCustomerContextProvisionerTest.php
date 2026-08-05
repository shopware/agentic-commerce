<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\CustomerResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\Country\SalesChannel\CountryRouteResponse;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerAddressResolver;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisioner;
use Ucp\Sdk\Model\Common\Buyer;

/** @internal */
#[CoversClass(GuestCustomerContextProvisioner::class)]
final class GuestCustomerContextProvisionerTest extends TestCase
{
    #[Test]
    public function testItRegistersGuestCustomerThroughStoreApiRegisterRoute(): void
    {
        $originalContext = $this->createMock(SalesChannelContext::class);
        $originalContext->method('getCustomer')->willReturn(null);
        $originalContext->method('getSalesChannelId')->willReturn('22222222222222222222222222222222');
        $originalContext->method('getLanguageId')->willReturn('33333333333333333333333333333333');
        $originalContext->method('getCurrencyId')->willReturn('44444444444444444444444444444444');
        $originalContext->method('getDomainId')->willReturn('55555555555555555555555555555555');

        $registeredCustomer = new CustomerEntity();
        $registeredCustomer->setId('66666666666666666666666666666666');

        $registerRoute = $this->createMock(AbstractRegisterRoute::class);
        $registerRoute->expects(static::once())
            ->method('register')
            ->willReturnCallback(static function (
                RequestDataBag $data,
                SalesChannelContext $routeContext,
                bool $validateStorefrontUrl,
            ) use ($originalContext, $registeredCustomer): CustomerResponse {
                self::assertSame($originalContext, $routeContext);
                self::assertFalse($validateStorefrontUrl);
                self::assertTrue($data->getBoolean('guest'));
                self::assertSame('ada@example.com', $data->get('email'));
                self::assertSame('Ada', $data->get('firstName'));
                self::assertSame('Lovelace', $data->get('lastName'));

                $billingAddress = $data->get('billingAddress');
                self::assertInstanceOf(DataBag::class, $billingAddress);
                self::assertSame('Main Street 1', $billingAddress->get('street'));
                self::assertSame('12345', $billingAddress->get('zipcode'));
                self::assertSame('Example City', $billingAddress->get('city'));
                self::assertSame('11111111111111111111111111111111', $billingAddress->get('countryId'));
                self::assertSame('+49 1234', $billingAddress->get('phoneNumber'));

                // One address only, so no shippingAddress key: Shopware defaults shipping
                // to billing, which is what every session written before the two were
                // separated relies on.
                self::assertNull($data->get('shippingAddress'));

                $response = new CustomerResponse($registeredCustomer);
                $response->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, 'rotated-context-token');

                return $response;
            });

        $customerContext = $this->createMock(SalesChannelContext::class);
        $contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $contextService->expects(static::once())
            ->method('get')
            ->willReturnCallback(static function (SalesChannelContextServiceParameters $parameters) use ($customerContext): SalesChannelContext {
                self::assertSame('22222222222222222222222222222222', $parameters->getSalesChannelId());
                self::assertSame('rotated-context-token', $parameters->getToken());
                self::assertSame('33333333333333333333333333333333', $parameters->getLanguageId());
                self::assertSame('44444444444444444444444444444444', $parameters->getCurrencyId());
                self::assertSame('55555555555555555555555555555555', $parameters->getDomainId());
                self::assertSame('66666666666666666666666666666666', $parameters->getCustomerId());

                return $customerContext;
            });

        $provisioner = new GuestCustomerContextProvisioner(
            $registerRoute,
            $contextService,
            new GuestCustomerAddressResolver($this->countryRoute()),
        );

        self::assertSame($customerContext, $provisioner->ensureGuestCustomer(
            $originalContext,
            new Buyer('ada@example.com', 'Ada', 'Lovelace', '+49 1234'),
            [
                'street' => 'Main Street 1',
                'zipcode' => '12345',
                'city' => 'Example City',
                'countryCode' => 'DE',
            ],
        ));
    }

    private function countryRoute(): AbstractCountryRoute
    {
        $country = new CountryEntity();
        $country->setId('11111111111111111111111111111111');

        $countryRoute = $this->createMock(AbstractCountryRoute::class);
        $countryRoute->method('load')->willReturnCallback(
            static function (
                \Symfony\Component\HttpFoundation\Request $_request,
                Criteria $criteria,
                SalesChannelContext $_context,
            ) use ($country): CountryRouteResponse {
                $countries = new CountryCollection([$country]);

                return new CountryRouteResponse(new EntitySearchResult(
                    'country',
                    $countries->count(),
                    $countries,
                    null,
                    $criteria,
                    Context::createDefaultContext(),
                ));
            },
        );

        return $countryRoute;
    }

    #[Test]
    public function testItRegistersBothAddressesWhenTheAgentStatesThemSeparately(): void
    {
        $originalContext = $this->createMock(SalesChannelContext::class);
        $originalContext->method('getCustomer')->willReturn(null);
        $originalContext->method('getSalesChannelId')->willReturn('22222222222222222222222222222222');
        $originalContext->method('getLanguageId')->willReturn('33333333333333333333333333333333');
        $originalContext->method('getCurrencyId')->willReturn('44444444444444444444444444444444');
        $originalContext->method('getDomainId')->willReturn('55555555555555555555555555555555');

        $registeredCustomer = new CustomerEntity();
        $registeredCustomer->setId('66666666666666666666666666666666');

        $registerRoute = $this->createMock(AbstractRegisterRoute::class);
        $registerRoute->expects(static::once())
            ->method('register')
            ->willReturnCallback(static function (
                RequestDataBag $data,
                SalesChannelContext $routeContext,
                bool $validateStorefrontUrl,
            ) use ($registeredCustomer): CustomerResponse {
                // The whole point of the change: the UCP billing address goes to
                // Shopware's billing address and the fulfillment destination goes to its
                // shipping address, instead of the destination being registered as
                // billing and shipping silently defaulting to it.
                $billingAddress = $data->get('billingAddress');
                self::assertInstanceOf(DataBag::class, $billingAddress);
                self::assertSame('Billing Street 2', $billingAddress->get('street'));

                $shippingAddress = $data->get('shippingAddress');
                self::assertInstanceOf(DataBag::class, $shippingAddress);
                self::assertSame('Main Street 1', $shippingAddress->get('street'));
                self::assertSame('Example City', $shippingAddress->get('city'));

                $response = new CustomerResponse($registeredCustomer);
                $response->headers->set(PlatformRequest::HEADER_CONTEXT_TOKEN, 'rotated-context-token');

                return $response;
            });

        $customerContext = $this->createMock(SalesChannelContext::class);
        $contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $contextService->method('get')->willReturn($customerContext);

        $provisioner = new GuestCustomerContextProvisioner(
            $registerRoute,
            $contextService,
            new GuestCustomerAddressResolver($this->countryRoute()),
        );

        self::assertSame($customerContext, $provisioner->ensureGuestCustomer(
            $originalContext,
            new Buyer('ada@example.com', 'Ada', 'Lovelace', '+49 1234'),
            ['street' => 'Billing Street 2', 'zipcode' => '20095', 'city' => 'Hamburg', 'countryCode' => 'DE'],
            ['street' => 'Main Street 1', 'zipcode' => '12345', 'city' => 'Example City', 'countryCode' => 'DE'],
        ));
    }
}
