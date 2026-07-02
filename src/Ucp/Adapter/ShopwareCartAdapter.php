<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Adapter;

use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCartGateway;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Ucp\Sdk\Adapter\CartAdapterInterface;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareCartAdapter implements CartAdapterInterface
{
    public function __construct(
        private readonly ShopwareCartGateway $gateway,
        private readonly ContextTokenGenerator $contextTokenGenerator,
    ) {
    }

    public function createCart(CartCreateRequest $request, RequestContext $context): Cart
    {
        return $this->gateway->createCart($this->contextTokenGenerator->generate(), $request->lineItems, [], $context);
    }

    public function getCart(string $id, RequestContext $context): Cart
    {
        return $this->gateway->getCart($id, $context);
    }

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
    {
        return $this->gateway->updateCart($request->id, $request->lineItems, [], $context);
    }

    public function cancelCart(string $id, RequestContext $context): Cart
    {
        return $this->gateway->cancelCart($id, $context);
    }
}
