<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartLoadRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/** @internal */
final class RecordingCartLoadRoute extends AbstractCartLoadRoute
{
    /**
     * @var list<string>
     */
    public array $loadedTokens = [];

    public function __construct(private readonly Cart $cart)
    {
    }

    public function getDecorated(): AbstractCartLoadRoute
    {
        throw new \BadMethodCallException('Decoration is not supported in tests.');
    }

    public function load(Request $request, SalesChannelContext $context): CartResponse
    {
        $token = $request->query->get('token');
        if (\is_string($token)) {
            $this->loadedTokens[] = $token;
        }

        return new CartResponse($this->cart);
    }
}
