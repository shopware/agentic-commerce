<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Adapter\DiscountAdapterInterface;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final readonly class DiscountCapability implements DiscountCapabilityInterface
{
    public function __construct(
        private DiscountAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_DISCOUNT);
    }

    public function applyCartDiscount(string $cartId, DiscountCode $discount, RequestContext $context): Cart
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT, 'Discount capability is disabled for this sales channel.');

        return $this->adapter->applyCartDiscount($cartId, $discount, $context);
    }
}
