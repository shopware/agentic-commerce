<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

final readonly class PaymentTokenizationCapability implements TokenizationCapabilityInterface
{
    public function __construct(
        private PaymentHandlerRegistryInterface $paymentHandlerRegistry,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION);
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): array
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION, 'Payment tokenization capability is disabled for this sales channel.');

        $handler = $this->paymentHandlerRegistry->find($instrument->handlerId);
        if (null === $handler || !$handler->supportsTokenization()) {
            throw new UnsupportedCapabilityException(sprintf('Payment handler "%s" does not support UCP tokenization.', $instrument->handlerId));
        }

        $result = $handler->tokenize($instrument, $context);
        if (null === $result) {
            throw new UnsupportedCapabilityException(sprintf('Payment handler "%s" declined UCP tokenization.', $instrument->handlerId));
        }

        return $result;
    }
}
