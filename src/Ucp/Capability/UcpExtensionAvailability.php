<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Swag\AgenticCommerce\Ucp\Quote\QuoteGatewayInterface;
use Ucp\Sdk\Adapter\IdentityLinkingAdapterInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
final class UcpExtensionAvailability
{
    /** @var list<IdentityLinkingAdapterInterface>|null */
    private ?array $identityLinkingAdapters = null;

    /**
     * @param iterable<IdentityLinkingAdapterInterface> $identityLinkingAdapterIterable
     */
    public function __construct(
        private readonly iterable $identityLinkingAdapterIterable,
        private readonly PaymentHandlerRegistryInterface $paymentHandlerRegistry,
        private readonly ?QuoteGatewayInterface $quoteGateway = null,
    ) {
    }

    public function supportsIdentityLinking(): bool
    {
        return [] !== $this->allIdentityLinkingAdapters();
    }

    /**
     * Quotes need the commercial B2B backend: the gateway service only exists when
     * SwagCommercial is installed, and it reports whether Quote Management is licensed.
     */
    public function supportsQuotes(): bool
    {
        return true === $this->quoteGateway?->isAvailable();
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
