<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(OrderPermalinkBuilder::class)]
final class OrderPermalinkBuilderTest extends TestCase
{
    #[Test]
    public function testBuildsShopwaresOwnOrderPageAddressedByDeepLinkCode(): void
    {
        // The URL core itself puts in every order-state mail:
        // rawUrl('frontend.account.order.single.page', {'deepLinkCode': …}, domain).
        // It is the one that works whether or not the recipient is logged in — a guest is
        // asked for email and postcode, a customer sees the order — and the sender cannot
        // know which they are.
        $url = (new OrderPermalinkBuilder())->build($this->order('deep-link-code'), new RequestContext('shop.example'));

        self::assertSame('https://shop.example/account/order/deep-link-code', $url);
    }

    #[Test]
    public function testPrefersTheConfiguredBaseUriOverTheRequestHost(): void
    {
        $context = new RequestContext('shop.example', runtimeConfiguration: new RuntimeConfiguration(
            '2026-08-25',
            'https://storefront.example/',
        ));

        $url = (new OrderPermalinkBuilder())->build($this->order('code-1'), $context);

        self::assertSame('https://storefront.example/account/order/code-1', $url);
    }

    #[Test]
    public function testEncodesTheDeepLinkCode(): void
    {
        $url = (new OrderPermalinkBuilder())->build($this->order('a b/c'), new RequestContext('shop.example'));

        self::assertSame('https://shop.example/account/order/a%20b%2Fc', $url);
    }

    #[Test]
    public function testFallsBackToTheOrderListWhenTheOrderHasNoDeepLinkCode(): void
    {
        // `deep_link_code` is nullable in the DAL and `permalink_url` is required, so there
        // has to be an answer. The list is the truthful one — it beats a URL built from an
        // order id, which the deep-link route cannot resolve at all.
        $url = (new OrderPermalinkBuilder())->build($this->order(null), new RequestContext('shop.example'));

        self::assertSame('https://shop.example/account/order', $url);
    }

    private function order(?string $deepLinkCode): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('99999999999999999999999999999999');
        if (null !== $deepLinkCode) {
            $order->setDeepLinkCode($deepLinkCode);
        }

        return $order;
    }
}
