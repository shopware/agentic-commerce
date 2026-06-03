<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final readonly class CheckoutCapability implements CheckoutCapabilityInterface
{
    public function __construct(
        private CheckoutAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_CHECKOUT);
    }

    public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT, 'Checkout capability is disabled for this sales channel.');

        return $this->adapter->createCheckout($request, $context);
    }

    public function getCheckout(string $id, RequestContext $context): Checkout
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT, 'Checkout capability is disabled for this sales channel.');

        return $this->adapter->getCheckout($id, $context);
    }

    public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT, 'Checkout capability is disabled for this sales channel.');

        return $this->adapter->updateCheckout($request, $context);
    }

    public function completeCheckout(string $id, RequestContext $context): Checkout
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT, 'Checkout capability is disabled for this sales channel.');

        return $this->adapter->completeCheckout($id, $context);
    }

    public function cancelCheckout(string $id, RequestContext $context): Checkout
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT, 'Checkout capability is disabled for this sales channel.');

        return $this->adapter->cancelCheckout($id, $context);
    }
}
