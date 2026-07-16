<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Swag\AgenticCommerce\Ucp\Ap2\Ap2MandateClaimsVerifierInterface;
use Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizerInterface;
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
     * @param iterable<PaymentAuthorizerInterface>        $paymentAuthorizerIterable
     */
    public function __construct(
        private readonly iterable $identityLinkingAdapterIterable,
        private readonly PaymentHandlerRegistryInterface $paymentHandlerRegistry,
        private readonly iterable $ap2CheckoutMandateVerifierIterable = [],
        private readonly iterable $paymentAuthorizerIterable = [],
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

    public function paymentHandlerSupportsTokenization(string $handlerId): bool
    {
        return $this->paymentHandlerRegistry->find($handlerId)?->supportsTokenization() ?? false;
    }

    /**
     * A delegated (non-tokenizing) handler can only complete a checkout when a
     * payment authorizer claims it — advertising it without one is a dead end.
     */
    public function hasPaymentAuthorizerFor(string $handlerId): bool
    {
        foreach ($this->paymentAuthorizerIterable as $authorizer) {
            if ($authorizer->supports($handlerId)) {
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
     * Removes capability descriptors this installation cannot serve at runtime
     * (no adapter, verifier, or handler registered). Profile advertisement and
     * capability negotiation must share this gate — otherwise agents negotiate
     * capabilities the shop cannot fulfil and checkouts dead-end.
     *
     * @param list<string> $descriptors
     *
     * @return list<string>
     */
    public function filterSupportedDescriptors(array $descriptors): array
    {
        if (!$this->supportsIdentityLinking()) {
            $descriptors = array_values(array_diff($descriptors, [UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING]));
        }

        if (!$this->supportsPaymentTokenization()) {
            $descriptors = array_values(array_diff($descriptors, [UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION]));
        }

        if (!$this->supportsAp2Mandates()) {
            $descriptors = array_values(array_diff($descriptors, [UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE]));
        }

        return $descriptors;
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
