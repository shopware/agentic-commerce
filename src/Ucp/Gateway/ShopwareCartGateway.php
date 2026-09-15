<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartDeleteRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemAddRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemRemoveRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemUpdateRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartLoadRoute;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductListRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Cart\CartSessionStore;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Common\LineItem as UcpLineItem;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[Package('checkout')]
final class ShopwareCartGateway
{
    public function __construct(
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly AbstractCartLoadRoute $cartLoadRoute,
        private readonly AbstractCartItemAddRoute $cartItemAddRoute,
        private readonly AbstractCartItemUpdateRoute $cartItemUpdateRoute,
        private readonly AbstractCartItemRemoveRoute $cartItemRemoveRoute,
        private readonly AbstractCartDeleteRoute $cartDeleteRoute,
        private readonly ShopwareDataMapper $mapper,
        private readonly ShopwareVersionDetector $versionDetector,
        private readonly CartSessionStore $cartSessions,
        private readonly AbstractProductListRoute $productListRoute,
    ) {
    }

    /**
     * @param list<UcpLineItem> $lineItems
     * @param list<string>      $discountCodes
     */
    public function createCart(string $token, array $lineItems, array $discountCodes, RequestContext $requestContext): \Ucp\Sdk\Model\Cart\Cart
    {
        $context = $this->contextResolver->resolve($token, $requestContext);
        $cart = $this->synchronize($context, $lineItems, $discountCodes);
        $this->cartSessions->register($context);

        return $this->mapper->toCart($cart, $context);
    }

    public function getCart(string $token, RequestContext $requestContext): \Ucp\Sdk\Model\Cart\Cart
    {
        $context = $this->contextResolver->resolve($token, $requestContext);
        $this->requireKnownCart($token, $context);
        $cart = $this->loadCart($context);

        return $this->mapper->toCart($cart, $context);
    }

    /**
     * @param list<UcpLineItem> $lineItems
     * @param list<string>      $discountCodes
     */
    public function updateCart(string $token, array $lineItems, array $discountCodes, RequestContext $requestContext): \Ucp\Sdk\Model\Cart\Cart
    {
        $context = $this->contextResolver->resolve($token, $requestContext);
        $this->requireKnownCart($token, $context);
        $cart = $this->synchronize($context, $lineItems, $discountCodes);

        return $this->mapper->toCart($cart, $context);
    }

    public function applyDiscountCode(string $token, string $discountCode, RequestContext $requestContext): \Ucp\Sdk\Model\Cart\Cart
    {
        $context = $this->contextResolver->resolve($token, $requestContext);
        $this->requireKnownCart($token, $context);
        $cart = $this->loadCart($context);

        if ('' === $discountCode || $this->hasPromotionCode($cart, $discountCode)) {
            return $this->mapper->toCart($cart, $context);
        }

        $cart = $this->cartItemAddRoute->add(new Request([], ['items' => [[
            'id' => $this->promotionLineItemId($discountCode),
            'type' => LineItem::PROMOTION_LINE_ITEM_TYPE,
            'referencedId' => $discountCode,
            'quantity' => 1,
        ]]]), $cart, $context, null)->getCart();

        return $this->mapper->toCart($cart, $context);
    }

    public function cancelCart(string $token, RequestContext $requestContext): \Ucp\Sdk\Model\Cart\Cart
    {
        $context = $this->contextResolver->resolve($token, $requestContext);
        $this->requireKnownCart($token, $context);
        $cart = $this->loadCart($context);

        if ($cart->getLineItems()->count() > 0) {
            $this->cartDeleteRoute->delete($context);
        }

        return $this->mapper->toCart($this->loadCart($context), $context);
    }

    /**
     * @return array{0: \Shopware\Core\System\SalesChannel\SalesChannelContext, 1: \Shopware\Core\Checkout\Cart\Cart}
     */
    public function loadCheckoutCart(string $token, RequestContext $requestContext): array
    {
        $context = $this->contextResolver->resolve($token, $requestContext);

        return [$context, $this->loadCart($context)];
    }

    /**
     * @param list<UcpLineItem> $lineItems
     * @param list<string>      $discountCodes
     *
     * @return array{0: \Shopware\Core\System\SalesChannel\SalesChannelContext, 1: \Shopware\Core\Checkout\Cart\Cart}
     */
    public function synchronizeCheckoutCart(string $token, array $lineItems, array $discountCodes, RequestContext $requestContext): array
    {
        $context = $this->contextResolver->resolve($token, $requestContext);
        $cart = $this->synchronize($context, $lineItems, $discountCodes);

        return [$context, $cart];
    }

    /**
     * @param list<UcpLineItem> $desiredLineItems
     * @param list<string>      $discountCodes
     */
    private function synchronize(\Shopware\Core\System\SalesChannel\SalesChannelContext $context, array $desiredLineItems, array $discountCodes): \Shopware\Core\Checkout\Cart\Cart
    {
        $cart = $this->loadCart($context);
        $productIds = [];
        $lineItemsByReferencedId = [];

        foreach ($cart->getLineItems() as $lineItem) {
            $referencedId = $lineItem->getReferencedId() ?? $lineItem->getId();
            $lineItemsByReferencedId[$referencedId] = $lineItem;
        }

        $removeIds = [];
        $updatePayload = [];
        $addItems = [];

        foreach ($desiredLineItems as $item) {
            $productIds[] = $item->id;

            $existing = $lineItemsByReferencedId[$item->id] ?? null;
            if ($existing instanceof LineItem) {
                if ($existing->getQuantity() !== $item->quantity) {
                    $updatePayload[] = [
                        'id' => $existing->getId(),
                        'quantity' => $item->quantity,
                    ];
                }

                continue;
            }

            $addItems[] = [
                'id' => $item->id,
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $item->id,
                'quantity' => $item->quantity,
            ];
        }

        foreach ($lineItemsByReferencedId as $referencedId => $lineItem) {
            if (LineItem::PRODUCT_LINE_ITEM_TYPE !== $lineItem->getType()) {
                continue;
            }

            if (!\in_array($referencedId, $productIds, true)) {
                $removeIds[] = $lineItem->getId();
            }
        }

        $promotionCodes = array_values(array_unique(array_filter($discountCodes, static fn (string $code): bool => '' !== $code)));
        foreach ($lineItemsByReferencedId as $lineItem) {
            if (LineItem::PROMOTION_LINE_ITEM_TYPE !== $lineItem->getType()) {
                continue;
            }

            $code = $lineItem->getReferencedId() ?? '';
            if ('' === $code || \in_array($code, $promotionCodes, true)) {
                continue;
            }

            $removeIds[] = $lineItem->getId();
        }

        $presentPromotionCodes = [];
        foreach ($lineItemsByReferencedId as $lineItem) {
            if (LineItem::PROMOTION_LINE_ITEM_TYPE === $lineItem->getType() && null !== $lineItem->getReferencedId()) {
                $presentPromotionCodes[] = $lineItem->getReferencedId();
            }
        }

        foreach ($promotionCodes as $code) {
            if (\in_array($code, $presentPromotionCodes, true)) {
                continue;
            }

            $addItems[] = [
                'id' => $this->promotionLineItemId($code),
                'type' => LineItem::PROMOTION_LINE_ITEM_TYPE,
                'referencedId' => $code,
                'quantity' => 1,
            ];
        }

        if ([] !== $removeIds) {
            $cart = $this->cartItemRemoveRoute->remove(new Request(['ids' => $removeIds]), $cart, $context)->getCart();
        }

        if ([] !== $updatePayload) {
            $cart = $this->cartItemUpdateRoute->change(new Request([], ['items' => $updatePayload]), $cart, $context)->getCart();
        }

        if ([] !== $addItems) {
            $cart = $this->cartItemAddRoute->add(new Request([], ['items' => $addItems]), $cart, $context, null)->getCart();
        }

        $this->assertRequestedProductsArePresent($cart, $desiredLineItems, $context);

        return $cart;
    }

    /**
     * Shopware drops a line item it cannot resolve and says nothing about it.
     *
     * A product that is not purchasable -- the parent of a variant product is the ordinary case --
     * is removed during cart calculation without an error on the cart, so the agent received
     * `201 Created`, `status: success`, no messages, and a cart with nothing in it. Silence is the
     * worst answer here: an agent has no way to tell "you asked for something unbuyable" from
     * "your order is fine", and the next call it makes is checkout.
     *
     * @param list<UcpLineItem> $desiredLineItems
     *
     * @throws ValidationException when a requested product did not end up in the cart
     */
    private function assertRequestedProductsArePresent(
        \Shopware\Core\Checkout\Cart\Cart $cart,
        array $desiredLineItems,
        \Shopware\Core\System\SalesChannel\SalesChannelContext $context,
    ): void {
        $present = [];
        foreach ($cart->getLineItems() as $lineItem) {
            if (LineItem::PRODUCT_LINE_ITEM_TYPE === $lineItem->getType()) {
                $present[$lineItem->getReferencedId() ?? $lineItem->getId()] = true;
            }
        }

        $missing = [];
        foreach ($desiredLineItems as $item) {
            if (!isset($present[$item->id])) {
                $missing[] = $item->id;
            }
        }

        $missing = array_values(array_unique($missing));
        if ([] === $missing) {
            return;
        }

        $alternatives = $this->purchasableVariantsOf($missing, $context);

        $errors = [];
        foreach ($missing as $index => $id) {
            $errors[] = \sprintf(
                '$.line_items[%d].item.id "%s" could not be added.%s',
                $index,
                $id,
                isset($alternatives[$id])
                    ? \sprintf(' It is the parent of a variant product; buy one of its variants instead: %s.', implode(', ', $alternatives[$id]))
                    : ' The product is not purchasable in this sales channel.',
            );
        }

        throw new ValidationException(\sprintf('%d requested line item(s) could not be added to the cart.', \count($missing)), $errors);
    }

    /**
     * The buyable variants of any requested id that turns out to be a parent, so the agent can
     * retry with a real one rather than discovering the catalog a second time.
     *
     * @param list<string> $ids
     *
     * @return array<string, list<string>> parent id => variant ids
     */
    private function purchasableVariantsOf(array $ids, \Shopware\Core\System\SalesChannel\SalesChannelContext $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('parentId', $ids));
        $criteria->addSorting(new FieldSorting('id'));
        $criteria->setLimit(50);

        $variants = [];
        foreach ($this->productListRoute->load($criteria, $context)->getProducts() as $variant) {
            $parentId = $variant->getParentId();
            if (null === $parentId) {
                continue;
            }

            $variants[$parentId][] = $variant->getId();
        }

        return $variants;
    }

    /**
     * @throws ResourceNotFoundException when no UCP cart was ever created under this id
     */
    private function requireKnownCart(string $token, \Shopware\Core\System\SalesChannel\SalesChannelContext $context): void
    {
        if ($this->cartSessions->isKnown($context)) {
            return;
        }

        throw new ResourceNotFoundException(\sprintf('Cart "%s" was not found.', $token));
    }

    private function loadCart(\Shopware\Core\System\SalesChannel\SalesChannelContext $context): \Shopware\Core\Checkout\Cart\Cart
    {
        return $this->cartLoadRoute->load(new Request(['token' => $context->getToken()]), $context)->getCart();
    }

    private function hasPromotionCode(\Shopware\Core\Checkout\Cart\Cart $cart, string $code): bool
    {
        foreach ($cart->getLineItems() as $lineItem) {
            if (LineItem::PROMOTION_LINE_ITEM_TYPE === $lineItem->getType() && $lineItem->getReferencedId() === $code) {
                return true;
            }
        }

        return false;
    }

    private function promotionLineItemId(string $code): string
    {
        $uniqueKey = 'promotion-'.$code;

        // Shopware 6.5 still accepts the human-readable promotion key here.
        // 6.6+ normalizes promotion line-item ids to hex UUIDs derived from that key.
        if (version_compare($this->versionDetector->currentVersion(), '6.6.0.0', '<')) {
            return $uniqueKey;
        }

        return \Shopware\Core\Framework\Uuid\Uuid::fromStringToHex($uniqueKey);
    }
}
