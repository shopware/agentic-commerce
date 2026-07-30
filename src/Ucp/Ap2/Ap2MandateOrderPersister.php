<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Ap2;

use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Persists the verified AP2 checkout mandate of a completed checkout onto the placed
 * order as custom fields. Mandates are the merchant's dispute evidence ("the Checkout
 * Mandate and Receipt MAY be able to be provided by the following roles: Shopping
 * Agent, Merchant" — AP2 specification) and may need to be retrieved and re-verified
 * months after the transaction, so the raw credential is stored verbatim alongside the
 * claims that were verified at completion time.
 */
final class Ap2MandateOrderPersister
{
    public const CUSTOM_FIELD_MANDATE = 'swag_agentic_commerce_ap2_checkout_mandate';
    public const CUSTOM_FIELD_CLAIMS = 'swag_agentic_commerce_ap2_mandate_claims';
    public const CUSTOM_FIELD_VERIFIED_AT = 'swag_agentic_commerce_ap2_mandate_verified_at';

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly Ap2VerifiedMandateRegistry $mandateRegistry,
        private readonly EntityRepository $orderRepository,
    ) {
    }

    public function persist(string $checkoutId, string $orderId, Context $context): void
    {
        $verified = $this->mandateRegistry->forCheckout($checkoutId);
        if (null === $verified) {
            return;
        }

        $this->orderRepository->update([[
            'id' => $orderId,
            'customFields' => [
                self::CUSTOM_FIELD_MANDATE => $verified['checkoutMandate'],
                self::CUSTOM_FIELD_CLAIMS => json_encode($verified['claims'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
                self::CUSTOM_FIELD_VERIFIED_AT => (new \DateTimeImmutable())->format(\DATE_ATOM),
            ],
        ]], $context);
    }
}
