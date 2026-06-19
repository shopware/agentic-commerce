<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemUpdateRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/** @internal */
final class RecordingCartItemUpdateRoute extends AbstractCartItemUpdateRoute
{
    /**
     * @var list<list<array{id: string, quantity: int}>>
     */
    public array $updatedPayloads = [];

    public function getDecorated(): AbstractCartItemUpdateRoute
    {
        throw new \BadMethodCallException('Decoration is not supported in tests.');
    }

    public function change(Request $request, Cart $cart, SalesChannelContext $context): CartResponse
    {
        /** @var list<array{id: string, quantity: int}> $payload */
        $payload = $request->request->all('items');
        $this->updatedPayloads[] = $payload;

        foreach ($payload as $item) {
            $cart->get($item['id'])?->setQuantity($item['quantity']);
        }

        return new CartResponse($cart);
    }
}
