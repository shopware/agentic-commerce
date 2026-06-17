<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManager;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Common\Buyer;

/** @internal */
final class CheckoutSessionManagerTest extends TestCase
{
    #[Test]
    public function testItSavesCompletedMetadataOnCustomerContextWhenCheckoutIdDidNotRotate(): void
    {
        $salesChannelId = '22222222222222222222222222222222';
        $contextToken = 'current-shopware-token';
        $customerId = '66666666666666666666666666666666';

        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->expects(static::once())
            ->method('save')
            ->willReturnCallback(static function (
                string $token,
                array $payload,
                string $savedSalesChannelId,
                ?string $savedCustomerId,
            ) use ($contextToken, $salesChannelId, $customerId): void {
                self::assertSame($contextToken, $token);
                self::assertSame($salesChannelId, $savedSalesChannelId);
                self::assertSame($customerId, $savedCustomerId);
                self::assertSame($contextToken, $payload['swagAgenticCommerce']['ucpCheckout']['shopwareContextToken']);
                self::assertSame('deep-link-code', $payload['swagAgenticCommerce']['ucpCheckout']['orderDeepLinkCode']);
            });

        (new CheckoutSessionManager(new CheckoutSessionStore($persister)))->saveForCheckoutId(
            $contextToken,
            $this->context($salesChannelId, $contextToken, $customerId),
            CheckoutStatus::Completed->value,
            new Buyer('ada@example.com'),
            orderId: '99999999999999999999999999999999',
            orderDeepLinkCode: 'deep-link-code',
        );
    }

    #[Test]
    public function testItKeepsStableCheckoutIdAliasWhenShopwareContextTokenRotated(): void
    {
        $checkoutId = 'original-ucp-checkout-id';
        $salesChannelId = '22222222222222222222222222222222';
        $contextToken = 'rotated-shopware-token';
        $customerId = '66666666666666666666666666666666';
        $calls = [];

        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->expects(static::exactly(2))
            ->method('save')
            ->willReturnCallback(static function (
                string $token,
                array $payload,
                string $savedSalesChannelId,
                ?string $savedCustomerId,
            ) use (&$calls): void {
                $calls[] = [$token, $payload, $savedSalesChannelId, $savedCustomerId];
            });

        (new CheckoutSessionManager(new CheckoutSessionStore($persister)))->saveForCheckoutId(
            $checkoutId,
            $this->context($salesChannelId, $contextToken, $customerId),
            CheckoutStatus::Completed->value,
            new Buyer('ada@example.com'),
            orderId: '99999999999999999999999999999999',
            orderDeepLinkCode: 'deep-link-code',
        );

        self::assertCount(2, $calls);
        self::assertSame([$contextToken, $salesChannelId, $customerId], [$calls[0][0], $calls[0][2], $calls[0][3]]);
        self::assertSame([$checkoutId, $salesChannelId, null], [$calls[1][0], $calls[1][2], $calls[1][3]]);
        self::assertSame($contextToken, $calls[0][1]['swagAgenticCommerce']['ucpCheckout']['shopwareContextToken']);
        self::assertSame($contextToken, $calls[1][1]['swagAgenticCommerce']['ucpCheckout']['shopwareContextToken']);
        self::assertSame('deep-link-code', $calls[0][1]['swagAgenticCommerce']['ucpCheckout']['orderDeepLinkCode']);
        self::assertSame('deep-link-code', $calls[1][1]['swagAgenticCommerce']['ucpCheckout']['orderDeepLinkCode']);
    }

    private function context(string $salesChannelId, string $token, string $customerId): SalesChannelContext
    {
        $customer = new CustomerEntity();
        $customer->setId($customerId);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);
        $context->method('getToken')->willReturn($token);
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }
}
