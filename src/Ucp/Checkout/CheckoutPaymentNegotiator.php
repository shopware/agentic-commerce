<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/**
 * Decides whether checkout completion must escalate to a browser handoff instead
 * of placing an order, based on the payment method the client committed.
 *
 * Opt-in per channel via `requireCommittedPaymentMethod` (default off): when off,
 * completion proceeds as before (UCP treats the payment object as optional). When
 * on, an order is placed only for a handler this shop can settle (registered in the
 * SDK PaymentHandlerRegistry); otherwise the checkout escalates.
 *
 * @internal
 */
final class CheckoutPaymentNegotiator
{
    public function __construct(
        private readonly PaymentHandlerRegistryInterface $paymentHandlerRegistry,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function shouldEscalate(string $salesChannelId, ?string $committedHandlerId): bool
    {
        if (!$this->requiresCommittedPaymentMethod($salesChannelId)) {
            return false;
        }

        return null === $committedHandlerId || null === $this->paymentHandlerRegistry->find($committedHandlerId);
    }

    private function requiresCommittedPaymentMethod(string $salesChannelId): bool
    {
        return filter_var(
            $this->systemConfigService->get('SwagAgenticCommerce.config.requireCommittedPaymentMethod', $salesChannelId),
            \FILTER_VALIDATE_BOOL,
        );
    }
}
