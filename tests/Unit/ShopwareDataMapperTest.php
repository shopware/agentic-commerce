<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Order\Adjustment;

/** @internal */
#[CoversClass(ShopwareDataMapper::class)]
final class ShopwareDataMapperTest extends TestCase
{
    /**
     * @param array<string, string> $expectedMessage
     */
    #[Test]
    #[DataProvider('orderStateProvider')]
    public function testOrderIncludesStateMessage(string $stateName, array $expectedMessage): void
    {
        $order = $this->order();
        $state = new StateMachineStateEntity();
        $state->setTechnicalName($stateName);
        $order->setStateMachineState($state);

        $view = (new ShopwareDataMapper())->toOrderView($order);

        self::assertCount(1, $view->messages);
        self::assertSame($expectedMessage, $view->messages[0]->toArray());
    }

    #[Test]
    public function testOrderWithoutStateDoesNotIncludeStateMessage(): void
    {
        $order = $this->order();

        self::assertSame([], (new ShopwareDataMapper())->toOrderView($order)->messages);
    }

    #[Test]
    public function testCancelledOrderIncludesCancellationAdjustment(): void
    {
        $order = $this->order();
        $order->setUpdatedAt(new \DateTimeImmutable('2026-07-14T10:30:00+00:00'));
        $state = new StateMachineStateEntity();
        $state->setTechnicalName(OrderStates::STATE_CANCELLED);
        $order->setStateMachineState($state);

        $view = (new ShopwareDataMapper())->toOrderView($order);

        self::assertSame([], $view->messages);
        self::assertSame([[
            'id' => '99999999999999999999999999999999-cancellation',
            'type' => 'cancellation',
            'occurred_at' => '2026-07-14T10:30:00+00:00',
            'status' => 'completed',
            'description' => 'The merchant cancelled this order.',
        ]], array_map(static fn (Adjustment $adjustment): array => $adjustment->toArray(), $view->adjustments));
        self::assertArrayNotHasKey('adjustments', $view->extra);
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function orderStateProvider(): iterable
    {
        yield 'open' => [OrderStates::STATE_OPEN, [
            'type' => 'info',
            'content' => 'The order is open.',
            'code' => 'order_open',
        ]];

        yield 'in progress' => [OrderStates::STATE_IN_PROGRESS, [
            'type' => 'info',
            'content' => 'The merchant is processing this order.',
            'code' => 'order_in_progress',
        ]];

        yield 'completed' => [OrderStates::STATE_COMPLETED, [
            'type' => 'info',
            'content' => 'The merchant completed this order.',
            'code' => 'order_completed',
        ]];
    }

    #[Test]
    public function testCartPromotionIsReportedAsAnItemsDiscountTotalInsteadOfALineItem(): void
    {
        $cart = (new ShopwareDataMapper())->toCart($this->cartWithPromotion(), $this->salesChannelContext());

        // A promotion line carries a negative unit price, and LineItem::toArray()
        // would emit it as a per-line `subtotal` — which types/total.json constrains
        // to `minimum: 0`, failing the response schema.
        self::assertCount(1, $cart->lineItems);
        self::assertSame('Nice Shirt', $cart->lineItems[0]->title);

        self::assertSame(-200, $this->total($cart->totals, 'items_discount'));
    }

    #[Test]
    public function testSubtotalIsReportedGrossOfTheDiscountSoTheBreakdownAddsUp(): void
    {
        $cart = (new ShopwareDataMapper())->toCart($this->cartWithPromotion(), $this->salesChannelContext());

        $subtotal = $this->total($cart->totals, 'subtotal');
        $itemsDiscount = $this->total($cart->totals, 'items_discount');

        // Shopware's positionPrice (8.00) is already net of the promotion, so the
        // discount is added back out of the subtotal and reported separately.
        self::assertSame(1000, $subtotal);
        self::assertSame(800, $subtotal + $itemsDiscount);
        self::assertGreaterThanOrEqual(0, $subtotal, 'types/total.json constrains subtotal to minimum 0.');
        self::assertLessThan(0, $itemsDiscount, 'types/total.json constrains items_discount to exclusiveMaximum 0.');
    }

    #[Test]
    public function testACartWithoutADiscountOmitsTheItemsDiscountTotal(): void
    {
        $cart = (new ShopwareDataMapper())->toCart($this->cartWithoutPromotion(), $this->salesChannelContext());

        // Zero would violate `exclusiveMaximum: 0`, so the entry must be absent
        // rather than present-and-zero.
        self::assertNull($this->total($cart->totals, 'items_discount'));
        self::assertSame(1000, $this->total($cart->totals, 'subtotal'));
    }

    /**
     * @param array<string, string> $expectedMessage
     */
    #[Test]
    #[DataProvider('cartErrorLevelProvider')]
    public function testCartErrorsAreMappedToTheThreeMessageTypesTheProtocolDefines(int $level, array $expectedMessage): void
    {
        $shopwareCart = $this->cartWithoutPromotion();
        $shopwareCart->addErrors(new CartErrorFixture($level, 'Discount Summer Sale has been added', 'promotion-discount-added'));

        $cart = (new ShopwareDataMapper())->toCart($shopwareCart, $this->salesChannelContext());

        self::assertCount(1, $cart->messages);
        self::assertSame($expectedMessage, $cart->messages[0]->toArray());
    }

    /**
     * @return iterable<string, array{int, array<string, string>}>
     */
    public static function cartErrorLevelProvider(): iterable
    {
        // types/message.json is a oneOf whose branches pin `type` with a const of
        // error, warning or info. Anything else matches no branch and takes the whole
        // response down with it.
        yield 'notice' => [Error::LEVEL_NOTICE, [
            'type' => 'info',
            'content' => 'Discount Summer Sale has been added',
            'code' => 'promotion-discount-added',
        ]];
        yield 'warning' => [Error::LEVEL_WARNING, [
            'type' => 'warning',
            'content' => 'Discount Summer Sale has been added',
            'code' => 'promotion-discount-added',
        ]];
        // Only message_error requires a severity.
        yield 'error' => [Error::LEVEL_ERROR, [
            'type' => 'error',
            'content' => 'Discount Summer Sale has been added',
            'severity' => 'recoverable',
            'code' => 'promotion-discount-added',
        ]];
    }

    #[Test]
    public function testOrderPromotionIsReportedAsAnItemsDiscountTotalInsteadOfALineItem(): void
    {
        $order = $this->order();
        $order->setPrice(new CartPrice(8.0, 8.0, 8.0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));
        $order->setLineItems(new OrderLineItemCollection([
            $this->orderLineItem('line-1', 'Nice Shirt', 10.0),
            $this->orderLineItem('line-2', 'Summer Sale', -2.0),
        ]));

        $view = (new ShopwareDataMapper())->toOrderView($order);

        self::assertCount(1, $view->lineItems);
        self::assertSame('Nice Shirt', $view->lineItems[0]->title);
        self::assertSame(-200, $this->total($view->totals, 'items_discount'));
        self::assertSame(1000, $this->total($view->totals, 'subtotal'));
    }

    /**
     * @param list<Money> $totals
     *
     * @return int|null the amount in minor units, null when the type is absent
     */
    private function total(array $totals, string $type): ?int
    {
        foreach ($totals as $total) {
            if ($total->type === $type) {
                $amount = $total->toArray()['amount'];

                return \is_int($amount) ? $amount : (int) $amount;
            }
        }

        return null;
    }

    private function cartWithPromotion(): Cart
    {
        $cart = $this->cartWithoutPromotion();
        $lineItems = $cart->getLineItems();
        $lineItems->add($this->cartLineItem('line-2', LineItem::PROMOTION_LINE_ITEM_TYPE, 'Summer Sale', -2.0));
        $cart->setLineItems($lineItems);

        // positionPrice sums every position, the promotion included.
        $cart->setPrice(new CartPrice(8.0, 8.0, 8.0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

        return $cart;
    }

    private function cartWithoutPromotion(): Cart
    {
        $taxes = new CalculatedTaxCollection();
        $taxRules = new TaxRuleCollection();

        $cart = new Cart('cart-token');
        $cart->setLineItems(new LineItemCollection([
            $this->cartLineItem('line-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'Nice Shirt', 10.0),
        ]));
        $cart->setPrice(new CartPrice(10.0, 10.0, 10.0, $taxes, $taxRules, CartPrice::TAX_STATE_GROSS));
        // Cart::getShippingCosts() sums the deliveries; with none set that is 0.00.

        return $cart;
    }

    private function cartLineItem(string $id, string $type, string $label, float $unitPrice): LineItem
    {
        $taxes = new CalculatedTaxCollection();
        $taxRules = new TaxRuleCollection();

        $lineItem = new LineItem($id, $type, $id, 1);
        $lineItem->setLabel($label);
        $lineItem->setPrice(new CalculatedPrice($unitPrice, $unitPrice, $taxes, $taxRules));

        return $lineItem;
    }

    private function orderLineItem(string $id, string $label, float $unitPrice): OrderLineItemEntity
    {
        $taxes = new CalculatedTaxCollection();
        $taxRules = new TaxRuleCollection();

        $lineItem = new OrderLineItemEntity();
        $lineItem->setId($id);
        $lineItem->setIdentifier($id);
        $lineItem->setLabel($label);
        $lineItem->setQuantity(1);
        $lineItem->setType($unitPrice < 0.0 ? LineItem::PROMOTION_LINE_ITEM_TYPE : LineItem::PRODUCT_LINE_ITEM_TYPE);
        $lineItem->setPrice(new CalculatedPrice($unitPrice, $unitPrice, $taxes, $taxRules));

        return $lineItem;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setId('99999999999999999999999999999999');
        $currency->setIsoCode('EUR');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getToken')->willReturn('cart-token');

        return $context;
    }

    #[Test]
    public function testCompletedCheckoutExposesRequiredOrderPermalink(): void
    {
        $order = $this->order();

        // continueUrl is null (the regression trigger); the explicit order
        // permalink must still populate the required order.permalink_url.
        $checkout = (new ShopwareDataMapper())->toCompletedCheckout(
            $order,
            'checkout-1',
            'USD',
            null,
            orderPermalinkUrl: 'https://shop.example/ucp/v1/orders/'.$order->getId(),
        );

        $array = $checkout->toArray();
        self::assertArrayHasKey('order', $array);
        self::assertSame(
            'https://shop.example/ucp/v1/orders/'.$order->getId(),
            $array['order']['permalink_url'],
        );
    }

    #[Test]
    public function testCompletedCheckoutFallsBackToContinueUrlForPermalink(): void
    {
        $order = $this->order();

        $checkout = (new ShopwareDataMapper())->toCompletedCheckout(
            $order,
            'checkout-1',
            'USD',
            'https://shop.example/continue',
        );

        self::assertSame('https://shop.example/continue', $checkout->toArray()['order']['permalink_url']);
    }

    private function order(): OrderEntity
    {
        $taxes = new CalculatedTaxCollection();
        $taxRules = new TaxRuleCollection();
        $order = new OrderEntity();
        $order->setId('99999999999999999999999999999999');
        $order->setPrice(new CartPrice(10.0, 10.0, 10.0, $taxes, $taxRules, CartPrice::TAX_STATE_GROSS));
        $order->setShippingCosts(new CalculatedPrice(0.0, 0.0, $taxes, $taxRules));

        return $order;
    }
}
