<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Enum\CheckoutStatus;

/**
 * Derives what completing a checkout would do from its current status.
 *
 * Split out of UcpCheckoutCompleteTool so the money path is testable: the tool
 * itself depends on the final ShoppingOperationExecutor and cannot be constructed
 * with a mock.
 *
 * @internal
 */
#[Package('checkout')]
final class UcpCheckoutCompletionPreview
{
    /**
     * @return list<string> reasons a commit would not place an order, empty when it would
     */
    public function blockers(mixed $status): array
    {
        $status = $status instanceof CheckoutStatus ? $status->value : $status;

        return match ($status) {
            CheckoutStatus::ReadyForComplete->value => [],
            // Completing an already completed checkout replays the existing order
            // instead of placing a second one, so it does not block a commit.
            CheckoutStatus::Completed->value => [],
            CheckoutStatus::Incomplete->value => ['Checkout is incomplete: finish it with shopware-ucp-checkout-update before completing.'],
            CheckoutStatus::RequiresEscalation->value => ['Checkout requires escalation and cannot be completed by an agent.'],
            CheckoutStatus::CompleteInProgress->value => ['Another completion for this checkout is already in flight; retry once it finishes.'],
            CheckoutStatus::Canceled->value => ['Checkout is canceled and can no longer be completed.'],
            default => [\sprintf('Checkout status "%s" is not a state this tool knows how to complete.', \is_string($status) ? $status : get_debug_type($status))],
        };
    }
}
