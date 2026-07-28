<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(OrderPermalinkBuilder::class)]
final class OrderPermalinkBuilderTest extends TestCase
{
    #[Test]
    public function testBuildsAbsoluteUcpOrderUrlFromHostWhenNoBaseUri(): void
    {
        $url = (new OrderPermalinkBuilder())->build('order-123', new RequestContext('shop.example'));

        self::assertSame('https://shop.example/ucp/v1/orders/order-123', $url);
    }

    #[Test]
    public function testEncodesOrderId(): void
    {
        $url = (new OrderPermalinkBuilder())->build('a b/c', new RequestContext('shop.example'));

        self::assertStringStartsWith('https://shop.example/ucp/v1/orders/', $url);
        self::assertStringContainsString('a%20b%2Fc', $url);
    }
}
