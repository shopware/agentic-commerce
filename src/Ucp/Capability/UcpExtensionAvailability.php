<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Swag\AgenticCommerce\Ucp\Ap2\Ap2MandateClaimsVerifierInterface;
use Ucp\Sdk\Adapter\IdentityLinkingAdapterInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
final class UcpExtensionAvailability
{
    /** @var list<IdentityLinkingAdapterInterface>|null */
    private ?array $identityLinkingAdapters = null;

    /**
     * @param iterable<IdentityLinkingAdapterInterface>   $identityLinkingAdapterIterable
     * @param iterable<Ap2MandateClaimsVerifierInterface> $ap2CheckoutMandateVerifierIterable
     */
    public function __construct(
        private readonly iterable $identityLinkingAdapterIterable,
        private readonly PaymentHandlerRegistryInterface $paymentHandlerRegistry,
        private readonly iterable $ap2CheckoutMandateVerifierIterable = [],
    ) {
    }

    public function supportsIdentityLinking(): bool
    {
        return [] !== $this->allIdentityLinkingAdapters();
    }

    public function supportsPaymentTokenization(): bool
    {
        foreach ($this->paymentHandlerRegistry->all() as $handler) {
            if ($handler->supportsTokenization()) {
                return true;
            }
        }

        return false;
    }

    public function supportsAp2Mandates(): bool
    {
        foreach ($this->ap2CheckoutMandateVerifierIterable as $_verifier) {
            return true;
        }

        return false;
    }

    /**
     * @return list<IdentityLinkingAdapterInterface>
     */
    private function allIdentityLinkingAdapters(): array
    {
        if (null === $this->identityLinkingAdapters) {
            $this->identityLinkingAdapters = array_values([...$this->identityLinkingAdapterIterable]);
        }

        return $this->identityLinkingAdapters;
    }
}
