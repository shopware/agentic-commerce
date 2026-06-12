<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemAddRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/** @internal */
final class RecordingCartItemAddRoute extends AbstractCartItemAddRoute
{
    /**
     * @var list<list<array{id: string, type: string, referencedId: string, quantity: int}>>
     */
    public array $addedPayloads = [];

    public function getDecorated(): AbstractCartItemAddRoute
    {
        throw new \BadMethodCallException('Decoration is not supported in tests.');
    }

    public function add(Request $request, Cart $cart, SalesChannelContext $context, ?array $items): CartResponse
    {
        /** @var list<array{id: string, type: string, referencedId: string, quantity: int}> $payload */
        $payload = $request->request->all('items');
        $this->addedPayloads[] = $payload;

        foreach ($payload as $item) {
            $cart->add((new LineItem($item['id'], $item['type'], $item['referencedId'], $item['quantity']))->setRemovable(true)->setStackable(true));
        }

        return new CartResponse($cart);
    }
}
