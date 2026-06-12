<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartDeleteRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemAddRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemRemoveRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemUpdateRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartLoadRoute;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\Common\LineItem as UcpLineItem;
use Ucp\Sdk\Model\RequestContext;

final readonly class ShopwareCartGateway
{
    public function __construct(
        private SalesChannelContextResolver $contextResolver,
        private AbstractCartLoadRoute $cartLoadRoute,
        private AbstractCartItemAddRoute $cartItemAddRoute,
        private AbstractCartItemUpdateRoute $cartItemUpdateRoute,
        private AbstractCartItemRemoveRoute $cartItemRemoveRoute,
        private AbstractCartDeleteRoute $cartDeleteRoute,
        private ShopwareDataMapper $mapper,
        private ShopwareVersionDetector $versionDetector,
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

        return $this->mapper->toCart($cart, $context);
    }

    public function getCart(string $token, RequestContext $requestContext): \Ucp\Sdk\Model\Cart\Cart
    {
        $context = $this->contextResolver->resolve($token, $requestContext);
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
        $cart = $this->synchronize($context, $lineItems, $discountCodes);

        return $this->mapper->toCart($cart, $context);
    }

    public function applyDiscountCode(string $token, string $discountCode, RequestContext $requestContext): \Ucp\Sdk\Model\Cart\Cart
    {
        $context = $this->contextResolver->resolve($token, $requestContext);
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
            \assert($item instanceof UcpLineItem);
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

        return $cart;
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
