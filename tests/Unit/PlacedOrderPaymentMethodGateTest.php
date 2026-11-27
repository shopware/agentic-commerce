<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\DebitPayment;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\InvoicePayment;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PrePayment;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AdvertisedPaymentHandlers;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\PlacedOrderPaymentMethodGate;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Swag\AgenticCommerce\Ucp\Payment\ShopwareOfflinePaymentHandler;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
#[CoversClass(PlacedOrderPaymentMethodGate::class)]
final class PlacedOrderPaymentMethodGateTest extends TestCase
{
    private const SALES_CHANNEL_ID = '00000000000000000000000000000001';
    private const EXTENSION_HANDLER_ID = 'com.example.psp';
    private const UNREGISTERED_HANDLER_ID = 'com.example.unregistered';
    private const EXTENSION_HANDLER_CLASS = 'Swag\\Example\\PspPaymentHandler';
    private const APP_HANDLER_IDENTIFIER = 'app\\ExampleApp_pay';

    private PaymentHandlerRegistryInterface&MockObject $handlerRegistry;

    /** @var EntityRepository<PaymentMethodCollection>&MockObject */
    private EntityRepository&MockObject $paymentMethodRepository;

    private SalesChannelPaymentMethods $salesChannelPaymentMethods;

    protected function setUp(): void
    {
        $this->handlerRegistry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $this->paymentMethodRepository = $this->createMock(EntityRepository::class);
        $this->salesChannelPaymentMethods = new SalesChannelPaymentMethods($this->paymentMethodRepository, $this->createMock(AbstractPaymentMethodRoute::class));
        $this->registerHandlers();
    }

    #[Test]
    public function testAPaymentMethodWithoutPaymentChangeAfterCheckoutIsRefusedRecoverablyWhereAnotherHandlerCanPay(): void
    {
        $this->methodsOfSalesChannel([InvoicePayment::class => [false], self::EXTENSION_HANDLER_CLASS => [true]]);
        $gate = $this->gateOnShopware('6.6.10.21', ShopwareOfflinePaymentHandler::INVOICE_ID, self::EXTENSION_HANDLER_ID);

        try {
            $gate->assertAllowed($this->paymentMethodWithAfterOrderEnabled(false), self::SALES_CHANNEL_ID, $this->requestContext());
            self::fail('Core refuses this payment method for a placed order, so the gate must too.');
        } catch (CompletionPaymentException $exception) {
            self::assertSame(CompletionPaymentException::PAYMENT_METHOD_NOT_CHANGEABLE, $exception->getErrorCode());
            self::assertSame('This payment method cannot pay an order that is already placed.', $exception->getMessage());
        }
    }

    #[Test]
    public function testAPaymentMethodWithoutPaymentChangeAfterCheckoutIsRefusedUnrecoverablyWhenNoAdvertisedHandlerCanPay(): void
    {
        $this->methodsOfSalesChannel([InvoicePayment::class => [false], PrePayment::class => [false]]);
        $gate = $this->gateOnShopware('6.7.13.0', ShopwareOfflinePaymentHandler::INVOICE_ID, ShopwareOfflinePaymentHandler::PREPAYMENT_ID);

        try {
            $gate->assertAllowed($this->paymentMethodWithAfterOrderEnabled(false), self::SALES_CHANNEL_ID, $this->requestContext());
            self::fail('No advertised handler can pay this placed order, so the refusal must say so.');
        } catch (CompletionPaymentException $exception) {
            self::assertSame(CompletionPaymentException::ORDER_CANNOT_BE_PAID_AGAIN, $exception->getErrorCode());
        }
    }

    #[Test]
    public function testAPaymentMethodWithPaymentChangeAfterCheckoutIsAllowedWithoutLookingAtTheHandlersWhereCoreEnforcesTheCheck(): void
    {
        $this->handlerRegistry->expects(static::never())->method('find');
        $this->paymentMethodRepository->expects(static::never())->method('search');

        $this->gateOnShopware('6.7.13.0', self::EXTENSION_HANDLER_ID)
            ->assertAllowed($this->paymentMethodWithAfterOrderEnabled(true), self::SALES_CHANNEL_ID, $this->requestContext());
    }

    #[Test]
    public function testAnyPaymentMethodIsAllowedWithoutLookingAtTheHandlersWhereCoreDoesNotEnforceTheCheck(): void
    {
        $this->handlerRegistry->expects(static::never())->method('find');
        $this->paymentMethodRepository->expects(static::never())->method('search');

        foreach (['6.5.8.18', '6.6.10.20', '6.7.12.0'] as $shopwareVersion) {
            $this->gateOnShopware($shopwareVersion, ShopwareOfflinePaymentHandler::INVOICE_ID)
                ->assertAllowed($this->paymentMethodWithAfterOrderEnabled(false), self::SALES_CHANNEL_ID, $this->requestContext());
        }
    }

    #[Test]
    public function testAnOrderCanBePaidAgainWhereCoreDoesNotEnforceTheCheck(): void
    {
        $this->methodsOfSalesChannel([InvoicePayment::class => [false]]);

        self::assertTrue($this->gateOnShopware('6.6.10.20', ShopwareOfflinePaymentHandler::INVOICE_ID)->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnOrderCanBePaidAgainByTheHandlerOfAPaymentExtensionWhoseMethodHasPaymentChangeAfterCheckout(): void
    {
        $this->methodsOfSalesChannel([InvoicePayment::class => [false], self::EXTENSION_HANDLER_CLASS => [true]]);
        $gate = $this->gateOnShopware('6.7.13.0', ShopwareOfflinePaymentHandler::INVOICE_ID, self::EXTENSION_HANDLER_ID);

        self::assertTrue($gate->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnOrderCanBePaidAgainByAPaymentExtensionWhoseHandlerClassRunsAnotherMethodWithPaymentChangeAfterCheckout(): void
    {
        $this->methodsOfSalesChannel([self::EXTENSION_HANDLER_CLASS => [false, true]]);

        self::assertTrue($this->gateOnShopware('6.7.13.0', self::EXTENSION_HANDLER_ID)->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnOrderCannotBePaidAgainByAPaymentExtensionWhoseMethodsLackPaymentChangeAfterCheckout(): void
    {
        $this->methodsOfSalesChannel([InvoicePayment::class => [false], self::EXTENSION_HANDLER_CLASS => [false, false]]);
        $gate = $this->gateOnShopware('6.7.13.0', ShopwareOfflinePaymentHandler::INVOICE_ID, self::EXTENSION_HANDLER_ID);

        self::assertFalse($gate->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnOrderCannotBePaidAgainByAPaymentExtensionWhenOnlyAnOfflineMethodHasPaymentChangeAfterCheckout(): void
    {
        $this->methodsOfSalesChannel([InvoicePayment::class => [true], self::EXTENSION_HANDLER_CLASS => [false]]);

        self::assertFalse($this->gateOnShopware('6.7.13.0', self::EXTENSION_HANDLER_ID)->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnOrderCannotBePaidAgainByAPaymentExtensionWhenOnlyDirectDebitHasPaymentChangeAfterCheckout(): void
    {
        $this->methodsOfSalesChannel([DebitPayment::class => [true], self::EXTENSION_HANDLER_CLASS => [false]]);

        self::assertFalse($this->gateOnShopware('6.7.13.0', self::EXTENSION_HANDLER_ID)->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnOrderCannotBePaidAgainByAPaymentExtensionWhenOnlyAnAppPaymentMethodHasPaymentChangeAfterCheckout(): void
    {
        $this->methodsOfSalesChannel([self::APP_HANDLER_IDENTIFIER => [true], self::EXTENSION_HANDLER_CLASS => [false]]);

        self::assertFalse($this->gateOnShopware('6.7.13.0', self::EXTENSION_HANDLER_ID)->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnOrderCanBePaidAgainByAPaymentExtensionAlongsideDirectDebitAndAppPaymentMethods(): void
    {
        $this->methodsOfSalesChannel([DebitPayment::class => [false], self::APP_HANDLER_IDENTIFIER => [false], self::EXTENSION_HANDLER_CLASS => [true]]);

        self::assertTrue($this->gateOnShopware('6.7.13.0', self::EXTENSION_HANDLER_ID)->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnOrderCanBePaidAgainByAnOfflineHandlerWhoseMethodHasPaymentChangeAfterCheckout(): void
    {
        $this->methodsOfSalesChannel([InvoicePayment::class => [false], PrePayment::class => [true]]);
        $gate = $this->gateOnShopware('6.6.10.21', ShopwareOfflinePaymentHandler::INVOICE_ID, ShopwareOfflinePaymentHandler::PREPAYMENT_ID);

        self::assertTrue($gate->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnOrderCannotBePaidAgainWhenEveryAdvertisedOfflineHandlerLacksPaymentChangeAfterCheckout(): void
    {
        $this->methodsOfSalesChannel([InvoicePayment::class => [false], PrePayment::class => [false]]);
        $gate = $this->gateOnShopware('6.7.13.0', ShopwareOfflinePaymentHandler::INVOICE_ID, ShopwareOfflinePaymentHandler::PREPAYMENT_ID);

        self::assertFalse($gate->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnOfflineHandlerIsJudgedByTheFirstMethodOfItsHandlerClass(): void
    {
        $this->methodsOfSalesChannel([InvoicePayment::class => [false, true]]);

        self::assertFalse($this->gateOnShopware('6.7.13.0', ShopwareOfflinePaymentHandler::INVOICE_ID)->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnAdvertisedOfflineHandlerWithoutAMethodInTheSalesChannelCannotPay(): void
    {
        $this->methodsOfSalesChannel([]);

        self::assertFalse($this->gateOnShopware('6.7.13.0', ShopwareOfflinePaymentHandler::INVOICE_ID)->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnAdvertisedHandlerIdWithoutARegisteredHandlerCannotPay(): void
    {
        $this->paymentMethodRepository->expects(static::never())->method('search');

        self::assertFalse($this->gateOnShopware('6.7.13.0', self::UNREGISTERED_HANDLER_ID)->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testAnAdvertisedHandlerIdWithoutARegisteredHandlerDoesNotHideAHandlerThatCanPay(): void
    {
        $this->methodsOfSalesChannel([InvoicePayment::class => [true]]);
        $gate = $this->gateOnShopware('6.7.13.0', self::UNREGISTERED_HANDLER_ID, ShopwareOfflinePaymentHandler::INVOICE_ID);

        self::assertTrue($gate->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext()));
    }

    #[Test]
    public function testThePaymentMethodsOfTheSalesChannelAreReadWithOneSearchForAllAdvertisedHandlers(): void
    {
        $searchedCriteria = [];
        $this->paymentMethodRepository->expects(static::once())->method('search')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use (&$searchedCriteria): EntitySearchResult {
                $searchedCriteria[] = $criteria;

                return new EntitySearchResult('payment_method', 0, new PaymentMethodCollection(), null, $criteria, $context);
            },
        );

        $this->gateOnShopware('6.7.13.0', ShopwareOfflinePaymentHandler::INVOICE_ID, ShopwareOfflinePaymentHandler::PREPAYMENT_ID, self::EXTENSION_HANDLER_ID)
            ->canBePaidByAdvertisedHandler(self::SALES_CHANNEL_ID, $this->requestContext());

        self::assertCount(1, $searchedCriteria);
    }

    private function gateOnShopware(string $shopwareVersion, string ...$advertisedHandlerIds): PlacedOrderPaymentMethodGate
    {
        $profileBuilder = RecordingProfileBuilder::advertising(...$advertisedHandlerIds);

        return new PlacedOrderPaymentMethodGate(
            new ShopwareVersionDetector(versionOverride: $shopwareVersion),
            new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $profileBuilder),
            $this->handlerRegistry,
            $this->salesChannelPaymentMethods,
        );
    }

    private function registerHandlers(): void
    {
        $offlineHandlers = [
            new ShopwareOfflinePaymentHandler(ShopwareOfflinePaymentHandler::INVOICE_ID, InvoicePayment::class, $this->salesChannelPaymentMethods),
            new ShopwareOfflinePaymentHandler(ShopwareOfflinePaymentHandler::PREPAYMENT_ID, PrePayment::class, $this->salesChannelPaymentMethods),
        ];
        $handlerByHandlerId = [
            ShopwareOfflinePaymentHandler::INVOICE_ID => $offlineHandlers[0],
            ShopwareOfflinePaymentHandler::PREPAYMENT_ID => $offlineHandlers[1],
            self::EXTENSION_HANDLER_ID => $this->createMock(PaymentHandlerInterface::class),
        ];
        $this->handlerRegistry->method('find')->willReturnCallback(static fn (string $handlerId): ?PaymentHandlerInterface => $handlerByHandlerId[$handlerId] ?? null);
    }

    /**
     * @param array<string, list<bool>> $afterOrderEnabledFlagsByHandlerClass one payment method per flag, in position order
     */
    private function methodsOfSalesChannel(array $afterOrderEnabledFlagsByHandlerClass): void
    {
        $this->paymentMethodRepository->method('search')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use ($afterOrderEnabledFlagsByHandlerClass): EntitySearchResult {
                $paymentMethodCollection = new PaymentMethodCollection();
                foreach ($afterOrderEnabledFlagsByHandlerClass as $handlerClass => $afterOrderEnabledFlags) {
                    foreach ($afterOrderEnabledFlags as $afterOrderEnabled) {
                        $paymentMethod = new PaymentMethodEntity();
                        $paymentMethod->setId(Uuid::randomHex());
                        $paymentMethod->setHandlerIdentifier($handlerClass);
                        $paymentMethod->setAfterOrderEnabled($afterOrderEnabled);
                        $paymentMethodCollection->add($paymentMethod);
                    }
                }

                return new EntitySearchResult('payment_method', $paymentMethodCollection->count(), $paymentMethodCollection, null, $criteria, $context);
            },
        );
    }

    private function paymentMethodWithAfterOrderEnabled(bool $afterOrderEnabled): PaymentMethodEntity
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setAfterOrderEnabled($afterOrderEnabled);

        return $paymentMethod;
    }

    private function requestContext(): RequestContext
    {
        return new RequestContext('shop.example', runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example'));
    }
}
