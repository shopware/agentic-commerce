<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Quote;

use Swag\AgenticCommerce\Ucp\Identity\AgentCustomerCredential;
use Ucp\Sdk\Model\RequestContext;

/**
 * Buyer-facing B2B quote operations, in the resolved customer's sales-channel
 * context. Implemented only when the commercial B2B backend is present, so the
 * capability degrades to "not advertised" on shops without it.
 *
 * @internal
 */
interface QuoteGatewayInterface
{
    /**
     * Whether quotes can be served right now: commercial backend installed and
     * the Quote Management feature licensed.
     */
    public function isAvailable(): bool;

    /**
     * Request a quote. Two-step in Shopware: the line items become a draft quote
     * which is then sent, moving it to `open`.
     *
     * @param list<array{product_id?: string, product_number?: string, quantity?: int, requested_unit_price?: float|int|string}> $lineItems
     */
    public function requestQuote(AgentCustomerCredential $credential, array $lineItems, ?string $comment, RequestContext $requestContext): QuoteSnapshot;

    public function getQuote(AgentCustomerCredential $credential, string $quoteId, RequestContext $requestContext): QuoteSnapshot;

    /**
     * The customer's quotes, newest first. Only quotes the resolved customer owns
     * are listed - the commercial listing route filters by customer and sales
     * channel itself.
     */
    public function listQuotes(AgentCustomerCredential $credential, int $limit, int $page, RequestContext $requestContext): QuoteList;

    /**
     * Counter-offer: new per-unit asks and/or a comment. Valid from `replied`.
     *
     * @param list<array{id?: string, product_id?: string, requested_unit_price?: float|int|string}> $lineItems
     */
    public function counterQuote(AgentCustomerCredential $credential, string $quoteId, array $lineItems, ?string $comment, RequestContext $requestContext): QuoteSnapshot;

    /**
     * Accept the offer. Accepting is ordering in Shopware's model; the returned
     * snapshot carries the resulting order reference.
     */
    public function acceptQuote(AgentCustomerCredential $credential, string $quoteId, RequestContext $requestContext): QuoteSnapshot;

    public function declineQuote(AgentCustomerCredential $credential, string $quoteId, ?string $comment, RequestContext $requestContext): QuoteSnapshot;
}
