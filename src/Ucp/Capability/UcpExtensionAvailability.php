<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Adapter\IdentityLinkingAdapterInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

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

    /**
     * @return list<IdentityLinkingAdapterInterface>
     */
    private function allIdentityLinkingAdapters(): array
    {
        if (null === $this->identityLinkingAdapters) {
            $this->identityLinkingAdapters = array_values(iterator_to_array($this->identityLinkingAdapterIterable));
        }

        return $this->identityLinkingAdapters;
    }
}
