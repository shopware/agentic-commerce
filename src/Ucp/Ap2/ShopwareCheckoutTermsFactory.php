<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Ap2;

use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Money;

/**
 * Converts the current checkout into stable AP2-comparable terms. Amounts are
 * integer minor units to avoid float drift during mandate comparison.
 */
final class ShopwareCheckoutTermsFactory
{
    /**
     * @return array{
     *     checkout_id: string,
     *     currency: string,
     *     line_items: list<array{id: string, quantity: int, unit_price: int}>,
     *     totals: array{total: int, tax: int, fulfillment: int}
     * }
     */
    public function terms(Checkout $checkout): array
    {
        return [
            'checkout_id' => $checkout->id,
            'currency' => $checkout->currency,
            'line_items' => array_map(
                fn (LineItem $item): array => [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'unit_price' => $this->minorUnits($item->price),
                ],
                $checkout->lineItems,
            ),
            'totals' => [
                'total' => $this->totalOfType($checkout, 'total'),
                'tax' => $this->totalOfType($checkout, 'tax'),
                'fulfillment' => $this->totalOfType($checkout, 'fulfillment'),
            ],
        ];
    }

    private function totalOfType(Checkout $checkout, string $type): int
    {
        foreach ($checkout->totals as $money) {
            if ($money instanceof Money && $money->type === $type) {
                return $this->minorUnits($money->amount);
            }
        }

        return 0;
    }

    private function minorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
