<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemRemoveRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/** @internal */
final class RecordingCartItemRemoveRoute extends AbstractCartItemRemoveRoute
{
    /**
     * @var list<string>
     */
    public array $removedIds = [];

    public function getDecorated(): AbstractCartItemRemoveRoute
    {
        throw new \BadMethodCallException('Decoration is not supported in tests.');
    }

    public function remove(Request $request, Cart $cart, SalesChannelContext $context): CartResponse
    {
        /** @var list<string> $ids */
        $ids = $request->query->all('ids');
        $this->removedIds = array_values(array_merge($this->removedIds, $ids));

        foreach ($ids as $id) {
            $cart->remove($id);
        }

        return new CartResponse($cart);
    }
}
