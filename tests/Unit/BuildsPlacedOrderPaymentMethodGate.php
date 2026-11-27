<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\InvoicePayment;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AdvertisedPaymentHandlers;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\PlacedOrderPaymentMethodGate;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Swag\AgenticCommerce\Ucp\Payment\ShopwareOfflinePaymentHandler;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/**
 * Builds the gate for a profile that advertises one handler: a payment extension's whose method allows paying after
 * checkout, or the invoice handler of a sales channel whose invoice method lacks "Allow payment change after checkout".
 *
 * @phpstan-require-extends TestCase
 *
 * @internal
 */
trait BuildsPlacedOrderPaymentMethodGate
{
    private function placedOrderGateOnShopware(string $shopwareVersion, bool $canBePaidAgain = true): PlacedOrderPaymentMethodGate
    {
        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $paymentMethods = new SalesChannelPaymentMethods($paymentMethodRepository, $this->createMock(AbstractPaymentMethodRoute::class));
        $invoiceHandler = new ShopwareOfflinePaymentHandler(ShopwareOfflinePaymentHandler::INVOICE_ID, InvoicePayment::class, $paymentMethods);
        $handlerRegistry = $this->createMock(PaymentHandlerRegistryInterface::class);

        if ($canBePaidAgain) {
            $advertisedHandlerId = 'com.example.psp';
            $paymentMethodHandlerClass = 'Swag\Example\PspPaymentHandler';
            $handlerRegistry->method('find')->willReturn($this->createMock(PaymentHandlerInterface::class));
        } else {
            $advertisedHandlerId = ShopwareOfflinePaymentHandler::INVOICE_ID;
            $paymentMethodHandlerClass = InvoicePayment::class;
            $handlerRegistry->method('find')->willReturn($invoiceHandler);
        }

        $paymentMethodRepository->method('search')->willReturnCallback(static function (Criteria $criteria, Context $context) use ($paymentMethodHandlerClass, $canBePaidAgain): EntitySearchResult {
            $paymentMethod = new PaymentMethodEntity();
            $paymentMethod->setId('00000000000000000000000000000009');
            $paymentMethod->setHandlerIdentifier($paymentMethodHandlerClass);
            $paymentMethod->setAfterOrderEnabled($canBePaidAgain);

            return new EntitySearchResult('payment_method', 1, new PaymentMethodCollection([$paymentMethod]), null, $criteria, $context);
        });

        $profileBuilder = RecordingProfileBuilder::advertising($advertisedHandlerId);

        return new PlacedOrderPaymentMethodGate(
            new ShopwareVersionDetector(versionOverride: $shopwareVersion),
            new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $profileBuilder),
            $handlerRegistry,
            $paymentMethods,
        );
    }
}
