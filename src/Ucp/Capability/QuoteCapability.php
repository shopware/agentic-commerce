<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Swag\AgenticCommerce\Ucp\Quote\QuoteGatewayInterface;
use Swag\AgenticCommerce\Ucp\Quote\QuoteSnapshot;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Vendor capability `com.shopware.quote`: the buyer-facing B2B
 * Request-for-Quote flow.
 *
 * Guard-then-delegate like the SDK-backed capabilities, but the backend is
 * optional: without SwagCommercial the gateway is absent, the capability is not
 * advertised (UcpExtensionAvailability + CapabilityFilteringProfileContributor)
 * and any call fails as unsupported.
 *
 * @internal
 */
final class QuoteCapability implements CapabilityInterface
{
    public function __construct(
        private readonly ?QuoteGatewayInterface $gateway = null,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_QUOTE);
    }

    /**
     * @param list<array{product_id?: string, product_number?: string, quantity?: int, requested_unit_price?: float|int|string}> $lineItems
     */
    public function requestQuote(string $contextToken, array $lineItems, ?string $comment, RequestContext $context): QuoteSnapshot
    {
        $this->assertEnabled($context);

        return $this->gateway()->requestQuote($contextToken, $lineItems, $comment, $context);
    }

    public function getQuote(string $contextToken, string $quoteId, RequestContext $context): QuoteSnapshot
    {
        $this->assertEnabled($context);

        return $this->gateway()->getQuote($contextToken, $quoteId, $context);
    }

    /**
     * @param list<array{id?: string, product_id?: string, requested_unit_price?: float|int|string}> $lineItems
     */
    public function counterQuote(string $contextToken, string $quoteId, array $lineItems, ?string $comment, RequestContext $context): QuoteSnapshot
    {
        $this->assertEnabled($context);

        return $this->gateway()->counterQuote($contextToken, $quoteId, $lineItems, $comment, $context);
    }

    public function acceptQuote(string $contextToken, string $quoteId, RequestContext $context): QuoteSnapshot
    {
        $this->assertEnabled($context);

        return $this->gateway()->acceptQuote($contextToken, $quoteId, $context);
    }

    public function declineQuote(string $contextToken, string $quoteId, ?string $comment, RequestContext $context): QuoteSnapshot
    {
        $this->assertEnabled($context);

        return $this->gateway()->declineQuote($contextToken, $quoteId, $comment, $context);
    }

    private function assertEnabled(RequestContext $context): void
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_QUOTE, 'Quote capability is disabled for this sales channel.');
    }

    private function gateway(): QuoteGatewayInterface
    {
        if (!$this->gateway instanceof QuoteGatewayInterface) {
            throw new UnsupportedCapabilityException('Quotes require the commercial B2B quote backend.');
        }

        return $this->gateway;
    }
}
