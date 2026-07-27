<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Quote;

/**
 * Transparent view of a quote as published to buyer agents.
 *
 * Price semantics are part of the published contract: all amounts are in
 * `currency`, `taxStatus` says whether the gross or the net total is the
 * customer-facing authoritative amount, and every line item price is per unit.
 *
 * @internal
 */
final class QuoteSnapshot
{
    /**
     * @param list<array{id: string, product_id: string|null, label: string, quantity: int, unit_price: float, total_price: float, requested_unit_price: float|null}> $lineItems
     * @param list<array{comment: string, author: string, created_at: string|null}>                                                                                   $comments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $quoteNumber,
        public readonly ?string $state,
        public readonly ?string $expirationDate,
        public readonly ?string $currency,
        public readonly ?float $totalGross,
        public readonly ?float $totalNet,
        public readonly ?string $taxStatus,
        public readonly array $lineItems,
        public readonly array $comments,
        public readonly ?string $orderId = null,
        public readonly ?string $orderNumber = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'quote_number' => $this->quoteNumber,
            'state' => $this->state,
            // Always present, even when null: agents must be able to read it to act
            // before the offer expires.
            'expiration_date' => $this->expirationDate,
            'currency' => $this->currency,
            'totals' => [
                'gross' => $this->totalGross,
                'net' => $this->totalNet,
                'tax_status' => $this->taxStatus,
            ],
            'line_items' => $this->lineItems,
            'comments' => $this->comments,
        ];

        if (null !== $this->orderId) {
            $payload['order'] = [
                'id' => $this->orderId,
                'order_number' => $this->orderNumber,
            ];
        }

        return $payload;
    }
}
