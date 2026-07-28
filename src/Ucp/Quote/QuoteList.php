<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Quote;

/**
 * A page of the customer's quotes, so an agent can rediscover state it saw once
 * (or poll several negotiations) without having stored every quote id.
 *
 * @internal
 */
final class QuoteList
{
    /**
     * @param list<QuoteSnapshot> $quotes
     */
    public function __construct(
        public readonly array $quotes,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $page,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quotes' => array_map(static fn (QuoteSnapshot $quote): array => $quote->toArray(), $this->quotes),
            'total' => $this->total,
            'limit' => $this->limit,
            'page' => $this->page,
        ];
    }
}
