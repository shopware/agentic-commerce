<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\PlacedOrderPaymentMethodGate;

/** @internal */
#[CoversClass(PlacedOrderPaymentMethodGate::class)]
final class PlacedOrderPaymentMethodGateTest extends TestCase
{
    #[Test]
    public function testAPaymentMethodWithoutPaymentChangeAfterCheckoutIsRefusedWhereCoreEnforcesIt(): void
    {
        try {
            $this->gateOnShopware('6.6.10.21')->assertAllowed($this->contextWithAfterOrderEnabled(false));
            self::fail('Core refuses this payment method for a placed order, so the gate must too.');
        } catch (CompletionPaymentException $exception) {
            self::assertSame(CompletionPaymentException::PAYMENT_METHOD_NOT_CHANGEABLE, $exception->getErrorCode());
            self::assertSame('This payment method cannot pay an order that is already placed.', $exception->getMessage());
        }
    }

    #[Test]
    public function testAPaymentMethodWithPaymentChangeAfterCheckoutIsAllowedWhereCoreEnforcesTheCheck(): void
    {
        $this->gateOnShopware('6.7.13.0')->assertAllowed($this->contextWithAfterOrderEnabled(true));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testAnyPaymentMethodIsAllowedWhereCoreDoesNotEnforceTheCheck(): void
    {
        foreach (['6.5.8.18', '6.6.10.20', '6.7.12.0'] as $shopwareVersion) {
            $this->gateOnShopware($shopwareVersion)->assertAllowed($this->contextWithAfterOrderEnabled(false));
        }

        $this->expectNotToPerformAssertions();
    }

    private function gateOnShopware(string $shopwareVersion): PlacedOrderPaymentMethodGate
    {
        return new PlacedOrderPaymentMethodGate(new ShopwareVersionDetector(versionOverride: $shopwareVersion));
    }

    private function contextWithAfterOrderEnabled(bool $afterOrderEnabled): SalesChannelContext
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setAfterOrderEnabled($afterOrderEnabled);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getPaymentMethod')->willReturn($paymentMethod);

        return $context;
    }
}
