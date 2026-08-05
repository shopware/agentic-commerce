<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Enum\AdjustmentStatus;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Catalog\Product;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\OrderConfirmation;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Link;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Order\Adjustment;
use Ucp\Sdk\Model\Order\OrderView;

/** @internal */
final class ShopwareDataMapper implements ShopwareDataMapperInterface
{
    public function toProduct(ProductEntity $product, SalesChannelContext $context, ?string $lookupInputId = null): Product
    {
        $name = $product->getTranslation('name');
        if (!\is_string($name) || '' === $name) {
            $name = $product->getName() ?: $product->getProductNumber() ?: $product->getId();
        }

        $cover = $product->getCover();
        $imageUrl = $cover?->getMedia()?->getUrl();
        $price = $product instanceof SalesChannelProductEntity ? $product->getCalculatedPrice()->getUnitPrice() : 0.0;
        $currency = $context->getCurrency()->getIsoCode();
        $extra = [];

        if (null !== $lookupInputId) {
            $extra['variants'] = [[
                'id' => $product->getId(),
                'title' => $name,
                'description' => ['plain' => $name],
                'price' => ['amount' => (int) round($price * 100), 'currency' => $currency],
                'inputs' => [['id' => $lookupInputId, 'match' => 'exact']],
            ]];
        }

        return new Product(
            $product->getId(),
            $name,
            $price,
            \is_string($imageUrl) && '' !== $imageUrl ? $imageUrl : null,
            // @phpstan-ignore-next-line argument.type -- SDK schema requires lookup inputs, but Product::$extra is typed too narrowly.
            $extra,
            $currency,
        );
    }

    public function toCart(Cart $cart, SalesChannelContext $context): \Ucp\Sdk\Model\Cart\Cart
    {
        return new \Ucp\Sdk\Model\Cart\Cart(
            $cart->getToken() ?: $context->getToken(),
            $this->mapShopwareLineItems($cart->getLineItems()),
            $context->getCurrency()->getIsoCode(),
            $this->cartMoneySummary($cart),
            $this->mapCartMessages($cart),
        );
    }

    public function toCheckout(
        Cart $cart,
        SalesChannelContext $context,
        CheckoutStatus $status,
        ?Buyer $buyer = null,
        ?string $continueUrl = null,
        ?OrderEntity $order = null,
    ): Checkout {
        return new Checkout(
            $cart->getToken() ?: $context->getToken(),
            $status,
            $context->getCurrency()->getIsoCode(),
            $this->mapShopwareLineItems($cart->getLineItems()),
            $this->cartMoneySummary($cart),
            $this->mapCartMessages($cart),
            null !== $continueUrl ? [new Link('continue', $continueUrl, 'Continue checkout')] : [],
            $buyer,
            $continueUrl,
            null,
            null !== $order ? new OrderConfirmation($order->getId(), $continueUrl) : null,
        );
    }

    public function toCompletedCheckout(OrderEntity $order, string $checkoutId, string $currencyCode, ?string $continueUrl = null): Checkout
    {
        return new Checkout(
            $checkoutId,
            CheckoutStatus::Completed,
            $currencyCode,
            $this->mapOrderLineItems($order),
            $this->orderMoneySummary($order),
            [],
            null !== $continueUrl ? [new Link('order', $continueUrl, 'Order details')] : [],
            $this->mapOrderBuyer($order),
            $continueUrl,
            $order->getCreatedAt()?->format(\DATE_ATOM),
            new OrderConfirmation($order->getId(), $continueUrl),
        );
    }

    public function toOrderView(OrderEntity $order, ?string $permalinkUrl = null, ?string $checkoutId = null): OrderView
    {
        return new OrderView(
            $order->getId(),
            $order->getCurrency()?->getIsoCode() ?? 'EUR',
            $this->mapOrderLineItems($order),
            $this->orderMoneySummary($order),
            $this->mapOrderMessages($order),
            null !== $permalinkUrl ? [new Link('self', $permalinkUrl, 'Order details')] : [],
            $this->mapOrderBuyer($order),
            $order->getCreatedAt()?->format(\DATE_ATOM),
            checkoutId: $checkoutId,
            permalinkUrl: $permalinkUrl,
            fulfillment: ['expectations' => [], 'events' => []],
            adjustments: $this->mapOrderAdjustments($order),
        );
    }

    /**
     * @return list<Message>
     *
     * @see https://github.com/agentic-commerce-alliance/ucp-php-sdk/blob/44a2b038726ecc5a78d5b7ccb90570ae27a66c3c/packages/core/resources/schema/pinned/2026-04-08/schemas/shopping/types/message_info.json
     */
    private function mapOrderMessages(OrderEntity $order): array
    {
        return match ($order->getStateMachineState()?->getTechnicalName()) {
            OrderStates::STATE_OPEN => [new Message('info', 'The order is open.', code: 'order_open')],
            OrderStates::STATE_IN_PROGRESS => [new Message('info', 'The merchant is processing this order.', code: 'order_in_progress')],
            OrderStates::STATE_COMPLETED => [new Message('info', 'The merchant completed this order.', code: 'order_completed')],
            default => [],
        };
    }

    /**
     * @return list<Adjustment>
     *
     * @see https://github.com/agentic-commerce-alliance/ucp-php-sdk/blob/44a2b038726ecc5a78d5b7ccb90570ae27a66c3c/packages/core/resources/schema/pinned/2026-04-08/schemas/shopping/order.json
     * @see https://github.com/agentic-commerce-alliance/ucp-php-sdk/blob/44a2b038726ecc5a78d5b7ccb90570ae27a66c3c/packages/core/resources/schema/pinned/2026-04-08/schemas/shopping/types/adjustment.json
     */
    private function mapOrderAdjustments(OrderEntity $order): array
    {
        if (OrderStates::STATE_CANCELLED !== $order->getStateMachineState()?->getTechnicalName()) {
            return [];
        }

        $occurredAt = $order->getUpdatedAt() ?? $order->getCreatedAt();
        if (null === $occurredAt) {
            return [];
        }

        return [new Adjustment(
            $order->getId().'-cancellation',
            'cancellation',
            $occurredAt->format(\DATE_ATOM),
            AdjustmentStatus::Completed,
            description: 'The merchant cancelled this order.',
        )];
    }

    /**
     * @return list<LineItem>
     */
    private function mapShopwareLineItems(LineItemCollection $lineItems): array
    {
        $payload = [];

        foreach ($lineItems as $lineItem) {
            if ($this->isDiscountLine($lineItem->getPrice()?->getUnitPrice())) {
                continue;
            }

            $label = $lineItem->getLabel() ?: ($lineItem->getReferencedId() ?? $lineItem->getId());
            $payload[] = $this->lineItem(
                $lineItem->getReferencedId() ?? $lineItem->getId(),
                $label,
                $lineItem->getPrice()?->getUnitPrice() ?? 0.0,
                $lineItem->getQuantity(),
                $lineItem->getCover()?->getUrl(),
                [
                    'type' => $lineItem->getType(),
                    'line_item_id' => $lineItem->getId(),
                ],
            );
        }

        return $payload;
    }

    /**
     * Decides whether a Shopware line belongs in `totals` instead of `line_items`.
     *
     * A promotion (and a credit, and a custom negative line) carries a negative unit
     * price. UCP has no such thing: `LineItem::toArray()` emits a per-line
     * `{"type": "subtotal"}` entry, and `types/total.json` constrains `subtotal` to
     * `minimum: 0`. Emitting one therefore made the whole response fail its own
     * schema — `discount.apply` most visibly, but equally `cart.get`, `cart.update`,
     * `checkout.get` and `checkout.update` whenever a promotion sat in the cart.
     *
     * The spec models these as a negative `items_discount` total instead, which is
     * where mapDiscountTotal() puts them.
     */
    private function isDiscountLine(?float $unitPrice): bool
    {
        return null !== $unitPrice && $unitPrice < 0.0;
    }

    /**
     * @return list<LineItem>
     */
    private function mapOrderLineItems(OrderEntity $order): array
    {
        $payload = [];

        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if ($this->isDiscountLine($lineItem->getPrice()?->getUnitPrice())) {
                continue;
            }

            $payload[] = $this->lineItem(
                $lineItem->getReferencedId() ?? $lineItem->getIdentifier(),
                $lineItem->getLabel(),
                $lineItem->getPrice()?->getUnitPrice() ?? 0.0,
                $lineItem->getQuantity(),
                $lineItem->getCover()?->getUrl(),
                [
                    'type' => $lineItem->getType(),
                    'line_item_id' => $lineItem->getId(),
                ],
            );
        }

        return $payload;
    }

    /**
     * Shopware's cart errors, as the three message types UCP defines.
     *
     * `types/message.json` is a oneOf over message_error, message_warning and
     * message_info, and each branch pins `type` with a `const`. A fourth spelling
     * matches no branch, so a single cart error made the WHOLE response fail
     * validation with `$ must match exactly one allowed schema` — which
     * `ShoppingOperationExecutor::response()` then turns into a server error for an
     * agent whose request was perfectly fine.
     *
     * That is not a rare shape: a successful `discount.apply` leaves
     * `promotion-discount-added` on the cart (`PromotionCartAddedInformationError`,
     * LEVEL_NOTICE, persistent), so applying a valid code failed by definition.
     *
     * Shopware's own three levels line up with UCP's three types, so the mapping is
     * the level. `getMessageKey()` stays the code: error_code, warning_code and
     * info_code are all freeform strings by specification.
     *
     * @return list<Message>
     */
    private function mapCartMessages(Cart $cart): array
    {
        $messages = [];

        foreach ($cart->getErrors() as $error) {
            $messages[] = new Message(
                match ($error->getLevel()) {
                    Error::LEVEL_ERROR => 'error',
                    Error::LEVEL_WARNING => 'warning',
                    default => 'info',
                },
                $error->getMessage(),
                // Only message_error requires a severity, and `recoverable` is the
                // honest one for a cart: the platform can change the line items or the
                // code and retry. A `requires_*` severity would contribute
                // `status: requires_escalation` and stall a checkout an agent could
                // have fixed itself.
                Error::LEVEL_ERROR === $error->getLevel() ? 'recoverable' : null,
                $error->getMessageKey(),
            );
        }

        return $messages;
    }

    private function mapOrderBuyer(OrderEntity $order): ?Buyer
    {
        $orderCustomer = $order->getOrderCustomer();
        if (null === $orderCustomer) {
            return null;
        }

        return new Buyer(
            $orderCustomer->getEmail(),
            $orderCustomer->getFirstName(),
            $orderCustomer->getLastName(),
            $order->getBillingAddress()?->getPhoneNumber(),
        );
    }

    /**
     * @return list<Money>
     */
    private function cartMoneySummary(Cart $cart): array
    {
        $itemsDiscount = 0.0;
        foreach ($cart->getLineItems() as $lineItem) {
            if ($this->isDiscountLine($lineItem->getPrice()?->getUnitPrice())) {
                $itemsDiscount += $lineItem->getPrice()?->getTotalPrice() ?? 0.0;
            }
        }

        return $this->moneySummary(
            $cart->getPrice()->getPositionPrice(),
            $cart->getShippingCosts()->getTotalPrice(),
            $cart->getPrice()->getTotalPrice(),
            $cart->getPrice()->getCalculatedTaxes(),
            $itemsDiscount,
        );
    }

    /**
     * @return list<Money>
     */
    private function orderMoneySummary(OrderEntity $order): array
    {
        $itemsDiscount = 0.0;
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            if ($this->isDiscountLine($lineItem->getPrice()?->getUnitPrice())) {
                $itemsDiscount += $lineItem->getPrice()?->getTotalPrice() ?? 0.0;
            }
        }

        return $this->moneySummary(
            $order->getPrice()->getPositionPrice(),
            $order->getShippingCosts()->getTotalPrice(),
            $order->getPrice()->getTotalPrice(),
            $order->getPrice()->getCalculatedTaxes(),
            $itemsDiscount,
        );
    }

    /**
     * Builds the UCP totals breakdown.
     *
     * `$positionPrice` is Shopware's sum over every position, discounts included, so
     * it is already net of the promotion. UCP wants the two reported separately —
     * a non-negative `subtotal` (types/total.json: `minimum: 0`) plus a strictly
     * negative `items_discount` (`exclusiveMaximum: 0`) — so the discount is added
     * back out of the subtotal and reported on its own. `subtotal + items_discount`
     * therefore returns to `$positionPrice`.
     *
     * `items_discount` is omitted entirely when nothing was discounted: zero would
     * violate `exclusiveMaximum: 0`.
     *
     * @param CalculatedTaxCollection $taxes
     * @param float                   $itemsDiscount sum of the excluded discount lines, zero or negative
     *
     * @return list<Money>
     */
    private function moneySummary(
        float $positionPrice,
        float $shipping,
        float $total,
        iterable $taxes,
        float $itemsDiscount = 0.0,
    ): array {
        $summary = [
            new Money('subtotal', $positionPrice - $itemsDiscount),
            new Money('fulfillment', $shipping),
            new Money('total', $total),
            new Money('tax', $this->totalTax($taxes)),
        ];

        if ($itemsDiscount < 0.0) {
            $summary[] = new Money('items_discount', $itemsDiscount);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function lineItem(
        string $id,
        string $label,
        float $unitPrice,
        int $quantity,
        ?string $coverUrl,
        array $metadata,
    ): LineItem {
        return new LineItem(
            $id,
            $label,
            $unitPrice,
            $quantity,
            \is_string($coverUrl) && '' !== $coverUrl ? $coverUrl : null,
            $metadata,
        );
    }

    /**
     * @param CalculatedTaxCollection $taxes
     */
    private function totalTax(iterable $taxes): float
    {
        $amount = 0.0;

        foreach ($taxes as $tax) {
            $amount += $tax->getTax();
        }

        return $amount;
    }
}
