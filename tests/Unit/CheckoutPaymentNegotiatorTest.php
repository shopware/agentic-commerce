<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutPaymentNegotiator;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
final class CheckoutPaymentNegotiatorTest extends TestCase
{
    private const SALES_CHANNEL_ID = '00000000000000000000000000000001';

    #[Test]
    public function testPolicyOffNeverEscalates(): void
    {
        $registry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $registry->expects(static::never())->method('find');

        $negotiator = new CheckoutPaymentNegotiator($registry, $this->config(false));

        self::assertFalse($negotiator->shouldEscalate(self::SALES_CHANNEL_ID, null));
        self::assertFalse($negotiator->shouldEscalate(self::SALES_CHANNEL_ID, 'com.example.unknown'));
    }

    #[Test]
    public function testPolicyOnEscalatesWhenNoHandlerCommitted(): void
    {
        $negotiator = new CheckoutPaymentNegotiator(
            $this->createMock(PaymentHandlerRegistryInterface::class),
            $this->config(true),
        );

        self::assertTrue($negotiator->shouldEscalate(self::SALES_CHANNEL_ID, null));
    }

    #[Test]
    public function testPolicyOnEscalatesWhenCommittedHandlerIsUnsupported(): void
    {
        $registry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $registry->method('find')->with('com.example.unknown')->willReturn(null);

        $negotiator = new CheckoutPaymentNegotiator($registry, $this->config(true));

        self::assertTrue($negotiator->shouldEscalate(self::SALES_CHANNEL_ID, 'com.example.unknown'));
    }

    #[Test]
    public function testPolicyOnCompletesWhenCommittedHandlerIsSupported(): void
    {
        $registry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $registry->method('find')
            ->with('com.shopware.x402')
            ->willReturn($this->createMock(PaymentHandlerInterface::class));

        $negotiator = new CheckoutPaymentNegotiator($registry, $this->config(true));

        self::assertFalse($negotiator->shouldEscalate(self::SALES_CHANNEL_ID, 'com.shopware.x402'));
    }

    private function config(bool $enabled): SystemConfigService
    {
        $service = $this->createMock(SystemConfigService::class);
        $service->method('get')->willReturn($enabled);

        return $service;
    }
}
