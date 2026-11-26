<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\CashPayment;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\InvoicePayment;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PrePayment;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Swag\AgenticCommerce\Ucp\Payment\ShopwareOfflinePaymentHandler;
use Swag\AgenticCommerce\Ucp\Profile\OfflinePaymentHandlerProfileContributor;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
#[CoversClass(OfflinePaymentHandlerProfileContributor::class)]
final class OfflinePaymentHandlerProfileContributorTest extends TestCase
{
    private const SALES_CHANNEL_ID = '00000000000000000000000000000001';
    private const OTHER_SALES_CHANNEL_ID = '00000000000000000000000000000002';
    private const INVOICE_PAYMENT_METHOD_ID = '00000000000000000000000000000003';
    private const BASE_URI = 'https://shop.example';

    #[Test]
    public function testAnOfflineHandlerWhoseMethodTheSalesChannelOffersStaysInTheProfile(): void
    {
        $profile = $this->profileWith('com.shopware.invoice', 'com.shopware.prepayment', 'com.shopware.cash_on_delivery');

        $contributed = $this->contributor(self::SALES_CHANNEL_ID, [InvoicePayment::class => self::INVOICE_PAYMENT_METHOD_ID])
            ->contribute($profile, new ProfileBuildInput(UcpProtocol::VERSION, self::BASE_URI));

        self::assertSame(['com.shopware.invoice'], array_keys($contributed->paymentHandlers));
    }

    #[Test]
    public function testASalesChannelThatOffersNoOfflineMethodAdvertisesNoOfflineHandler(): void
    {
        $contributed = $this->contributor(self::SALES_CHANNEL_ID, [])->contribute(
            $this->profileWith('com.shopware.invoice', 'com.shopware.prepayment'),
            new ProfileBuildInput(UcpProtocol::VERSION, self::BASE_URI),
        );

        self::assertSame([], $contributed->paymentHandlers);
    }

    #[Test]
    public function testAHandlerOfAnotherPluginIsNeverRemoved(): void
    {
        $contributed = $this->contributor(self::SALES_CHANNEL_ID, [])->contribute(
            $this->profileWith('com.shopware.invoice', 'com.example.psp'),
            new ProfileBuildInput(UcpProtocol::VERSION, self::BASE_URI),
        );

        self::assertSame(['com.example.psp'], array_keys($contributed->paymentHandlers));
    }

    #[Test]
    public function testABaseUriThatResolvesToNoSalesChannelAdvertisesNoOfflineHandler(): void
    {
        $contributed = $this->contributor(null, [InvoicePayment::class => self::INVOICE_PAYMENT_METHOD_ID])->contribute(
            $this->profileWith('com.shopware.invoice', 'com.example.psp'),
            new ProfileBuildInput(UcpProtocol::VERSION, self::BASE_URI),
        );

        self::assertSame(['com.example.psp'], array_keys($contributed->paymentHandlers));
    }

    #[Test]
    public function testTheMethodIsLookedUpOnTheSalesChannelOfTheBaseUri(): void
    {
        $searchedSalesChannelIds = [];
        $this->contributor(self::OTHER_SALES_CHANNEL_ID, [], $searchedSalesChannelIds)->contribute(
            $this->profileWith('com.shopware.invoice'),
            new ProfileBuildInput(UcpProtocol::VERSION, self::BASE_URI),
        );

        self::assertSame([self::OTHER_SALES_CHANNEL_ID, self::OTHER_SALES_CHANNEL_ID, self::OTHER_SALES_CHANNEL_ID], $searchedSalesChannelIds);
    }

    #[Test]
    public function testTheRestOfTheProfileIsKept(): void
    {
        $profile = new PlatformProfile(UcpProtocol::VERSION, [], [], [], [], ['2026-04-08' => 'https://shop.example/ucp/2026-04-08']);

        $contributed = $this->contributor(self::SALES_CHANNEL_ID, [])->contribute($profile, new ProfileBuildInput(UcpProtocol::VERSION, self::BASE_URI));

        self::assertEquals($profile, $contributed);
    }

    private function profileWith(string ...$handlerIds): PlatformProfile
    {
        $paymentHandlers = [];
        foreach ($handlerIds as $handlerId) {
            $paymentHandlers[$handlerId] = [new PaymentHandlerDescriptor($handlerId, $handlerId, UcpProtocol::VERSION, 'https://example.com/spec', 'https://example.com/config.json', ['https://example.com/instrument.json'])];
        }

        return new PlatformProfile(UcpProtocol::VERSION, [], [], $paymentHandlers);
    }

    /**
     * @param string|null           $resolvedSalesChannelId        the sales channel the base URI resolves to, null when it resolves to none
     * @param array<string, string> $paymentMethodIdByHandlerClass
     * @param list<string>|null     $searchedSalesChannelIds
     */
    private function contributor(?string $resolvedSalesChannelId, array $paymentMethodIdByHandlerClass, ?array &$searchedSalesChannelIds = null): OfflinePaymentHandlerProfileContributor
    {
        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $paymentMethodRepository->method('searchIds')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use ($paymentMethodIdByHandlerClass, &$searchedSalesChannelIds): IdSearchResult {
                $handlerClass = '';
                foreach ($criteria->getFilters() as $filter) {
                    if (!$filter instanceof EqualsFilter) {
                        continue;
                    }
                    if ('handlerIdentifier' === $filter->getField()) {
                        $handlerClass = (string) $filter->getValue();
                    }
                    if ('salesChannels.id' === $filter->getField() && null !== $searchedSalesChannelIds) {
                        $searchedSalesChannelIds[] = $filter->getValue();
                    }
                }

                $paymentMethodId = $paymentMethodIdByHandlerClass[$handlerClass] ?? null;

                return new IdSearchResult(null === $paymentMethodId ? 0 : 1, null === $paymentMethodId ? [] : [$paymentMethodId => ['primaryKey' => $paymentMethodId, 'data' => ['id' => $paymentMethodId]]], $criteria, $context);
            },
        );

        $paymentMethods = new SalesChannelPaymentMethods($paymentMethodRepository, $this->createMock(AbstractPaymentMethodRoute::class));
        $otherPluginHandler = $this->createMock(PaymentHandlerInterface::class);
        $handlerRegistry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $handlerRegistry->method('all')->willReturn([
            new ShopwareOfflinePaymentHandler(ShopwareOfflinePaymentHandler::INVOICE_ID, InvoicePayment::class, $paymentMethods),
            new ShopwareOfflinePaymentHandler(ShopwareOfflinePaymentHandler::PREPAYMENT_ID, PrePayment::class, $paymentMethods),
            new ShopwareOfflinePaymentHandler(ShopwareOfflinePaymentHandler::CASH_ON_DELIVERY_ID, CashPayment::class, $paymentMethods),
            $otherPluginHandler,
        ]);
        $otherPluginHandler->method('id')->willReturn('com.example.psp');

        return new OfflinePaymentHandlerProfileContributor($handlerRegistry, $this->domainResolver($resolvedSalesChannelId));
    }

    private function domainResolver(?string $salesChannelId): SalesChannelDomainResolver
    {
        $domains = new SalesChannelDomainCollection();
        if (null !== $salesChannelId) {
            $domain = new SalesChannelDomainEntity();
            $domain->setId('00000000000000000000000000000009');
            $domain->setSalesChannelId($salesChannelId);
            $domain->setLanguageId('00000000000000000000000000000008');
            $domain->setCurrencyId('00000000000000000000000000000007');
            $domain->setUrl(self::BASE_URI);
            $domains->add($domain);
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel_domain',
                $domains->count(),
                $domains,
                null,
                $criteria,
                $context,
            ),
        );

        return new SalesChannelDomainResolver($repository);
    }
}
