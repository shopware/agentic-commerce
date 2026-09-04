<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Adapter\PaymentAwareCheckoutAdapterInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Contract\PaymentAwareCheckoutCapabilityInterface;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;

/** @internal */
final class CheckoutCapability implements PaymentAwareCheckoutCapabilityInterface
{
    public function __construct(
        private readonly PaymentAwareCheckoutAdapterInterface $adapter,
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

    /**
     * The executor calls this one now that the capability opts in, so the instrument the
     * agent presented reaches the adapter instead of being validated and dropped.
     */
    public function completeCheckoutFromRequest(CheckoutCompleteRequest $request, RequestContext $context): Checkout
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT, 'Checkout capability is disabled for this sales channel.');

        return $this->adapter->completeCheckoutFromRequest($request, $context);
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
