<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Adapter\CartAdapterInterface;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class CartCapability implements CartCapabilityInterface
{
    public function __construct(
        private readonly CartAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_CART);
    }

    public function createCart(CartCreateRequest $request, RequestContext $context): Cart
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CART, 'Cart capability is disabled for this sales channel.');

        return $this->adapter->createCart($request, $context);
    }

    public function getCart(string $id, RequestContext $context): Cart
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CART, 'Cart capability is disabled for this sales channel.');

        return $this->adapter->getCart($id, $context);
    }

    public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CART, 'Cart capability is disabled for this sales channel.');

        return $this->adapter->updateCart($request, $context);
    }

    public function cancelCart(string $id, RequestContext $context): Cart
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CART, 'Cart capability is disabled for this sales channel.');

        return $this->adapter->cancelCart($id, $context);
    }
}
