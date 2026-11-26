<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentGate;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\RequestContext;

/**
 * Derives what completing a checkout would do from its current status and its payment.
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
     * @param list<string> $handlerIds
     */
    public function paymentRefusal(CompletionPaymentGate $gate, array $handlerIds, RequestContext $context): ?Message
    {
        try {
            return $gate->refusal($handlerIds, $context);
        } catch (UcpConfigException $exception) {
            return new Message('error', $exception->getMessage(), 'unrecoverable', $exception->getErrorCode());
        }
    }

    /**
     * @param array<mixed> $messages the messages of the checkout as `checkout.get` returns it
     *
     * @return list<string> reasons a commit would not place an order, empty when it would
     */
    public function blockers(mixed $status, ?Message $paymentRefusal = null, array $messages = []): array
    {
        $status = $status instanceof CheckoutStatus ? $status->value : $status;
        $statusBlockers = $this->failedPaymentBlockers($status, $messages) ?? $this->statusBlockers($status);

        // A completed checkout is replayed before the payment gate runs.
        if (null === $paymentRefusal || CheckoutStatus::Completed->value === $status) {
            return $statusBlockers;
        }

        return [...$statusBlockers, $paymentRefusal->content];
    }

    /**
     * Only a checkout whose order is placed reads `incomplete` with `payment_failed`: completing it pays that order again.
     *
     * @param array<mixed> $messages
     *
     * @return list<string>|null null when the checkout has no failed payment
     */
    private function failedPaymentBlockers(mixed $status, array $messages): ?array
    {
        if (CheckoutStatus::Incomplete->value !== $status) {
            return null;
        }

        foreach ($messages as $message) {
            if (!\is_array($message) || 'payment_failed' !== ($message['code'] ?? null)) {
                continue;
            }

            return 'unrecoverable' === ($message['severity'] ?? null)
                ? ['The payment failed and the order cannot be paid again through this checkout.']
                : [];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function statusBlockers(mixed $status): array
    {
        return match ($status) {
            CheckoutStatus::ReadyForComplete->value => [],
            // Completing an already completed checkout replays the existing order
            // instead of placing a second one, so it does not block a commit.
            CheckoutStatus::Completed->value => [],
            CheckoutStatus::Incomplete->value => ['Checkout is incomplete: finish it with update_checkout before completing.'],
            CheckoutStatus::RequiresEscalation->value => ['Checkout requires escalation and cannot be completed by an agent.'],
            CheckoutStatus::CompleteInProgress->value => ['Another completion for this checkout is already in flight; retry once it finishes.'],
            CheckoutStatus::Canceled->value => ['Checkout is canceled and can no longer be completed.'],
            default => [\sprintf('Checkout status "%s" is not a state this tool knows how to complete.', \is_string($status) ? $status : get_debug_type($status))],
        };
    }
}
