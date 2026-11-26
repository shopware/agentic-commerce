<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Common\Message;

/**
 * What the payment of a placed order means for its checkout.
 *
 * `Completed`: nothing is left for the agent to do. `CompleteInProgress`: the payment call has not
 * returned yet. `RequiresEscalation`: the buyer has to finish
 * the payment at `$redirectUrl`. `Incomplete`: the payment failed; the agent may complete again
 * while `$retryable` holds. `ReadyForComplete`: the order is still unpaid, and Complete Checkout pays it again.
 *
 * @internal
 */
final class CompletionPaymentResult
{
    public const SESSION_REDIRECT_URL_KEY = 'paymentRedirectUrl';
    public const SESSION_RETRYABLE_KEY = 'paymentRetryable';

    private const AWAITING_STATES = [
        OrderTransactionStates::STATE_OPEN,
        OrderTransactionStates::STATE_IN_PROGRESS,
        'unconfirmed',
    ];

    private const FAILED_STATES = [
        OrderTransactionStates::STATE_FAILED,
        OrderTransactionStates::STATE_CANCELLED,
    ];

    private function __construct(
        public readonly CheckoutStatus $status,
        public readonly ?string $redirectUrl = null,
        public readonly bool $retryable = true,
    ) {
    }

    public static function completed(): self
    {
        return new self(CheckoutStatus::Completed);
    }

    public static function awaitsBuyer(string $redirectUrl): self
    {
        return new self(CheckoutStatus::RequiresEscalation, $redirectUrl);
    }

    public static function inProgress(): self
    {
        return new self(CheckoutStatus::CompleteInProgress);
    }

    /**
     * @param bool $retryable false when completing the checkout again cannot pay the order
     */
    public static function failed(bool $retryable = true): self
    {
        return new self(CheckoutStatus::Incomplete, null, $retryable);
    }

    public static function readyToPayAgain(): self
    {
        return new self(CheckoutStatus::ReadyForComplete);
    }

    /**
     * @param array<string, mixed> $sessionMetadata the checkout session as the completion saved it
     */
    public static function ofPlacedOrder(array $sessionMetadata, OrderEntity $order): self
    {
        $sessionStatus = $sessionMetadata['status'] ?? null;
        if (CheckoutStatus::Completed->value === $sessionStatus) {
            return self::completed();
        }

        $transactionState = self::latestTransaction($order)?->getStateMachineState()?->getTechnicalName();
        $failed = \in_array($transactionState, self::FAILED_STATES, true);
        if (null !== $transactionState && !$failed && !\in_array($transactionState, self::AWAITING_STATES, true)) {
            return self::completed();
        }

        if (CheckoutStatus::ReadyForComplete->value === $sessionStatus) {
            return self::readyToPayAgain();
        }

        if (!$failed && CheckoutStatus::RequiresEscalation->value === $sessionStatus) {
            $redirectUrl = $sessionMetadata[self::SESSION_REDIRECT_URL_KEY] ?? null;

            return \is_string($redirectUrl) && '' !== $redirectUrl ? self::awaitsBuyer($redirectUrl) : self::completed();
        }

        if (!$failed && CheckoutStatus::CompleteInProgress->value === $sessionStatus) {
            return self::inProgress();
        }

        return self::failed(false !== ($sessionMetadata[self::SESSION_RETRYABLE_KEY] ?? null));
    }

    public function canBePaidAgain(): bool
    {
        // A payment call that is really running holds the completion lock, so one left in progress has died.
        return CheckoutStatus::CompleteInProgress === $this->status
            || CheckoutStatus::ReadyForComplete === $this->status
            || (CheckoutStatus::Incomplete === $this->status && $this->retryable);
    }

    /**
     * @return array<string, mixed> what the checkout session keeps next to its status
     */
    public function sessionData(): array
    {
        return array_filter([
            self::SESSION_REDIRECT_URL_KEY => $this->redirectUrl,
            self::SESSION_RETRYABLE_KEY => $this->retryable ? null : false,
        ], static fn (mixed $value): bool => null !== $value);
    }

    public function continueUrl(?string $checkoutContinueUrl): ?string
    {
        return $this->redirectUrl ?? $checkoutContinueUrl;
    }

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return match ($this->status) {
            CheckoutStatus::RequiresEscalation => [new Message(
                'error',
                'The buyer has to confirm the payment. Hand the buyer over to continue_url.',
                'requires_buyer_input',
                'payment_requires_buyer_input',
                '$.payment',
            )],
            CheckoutStatus::Incomplete => [$this->retryable
                ? new Message(
                    'error',
                    'The payment failed. The order is kept. Complete the checkout again to pay it, with the same or another payment instrument.',
                    'recoverable',
                    'payment_failed',
                    '$.payment.instruments[0]',
                )
                : new Message(
                    'error',
                    'The payment failed and the order cannot be paid again through this checkout. The order is kept, and the shop has to settle it with the buyer.',
                    'unrecoverable',
                    'payment_failed',
                    '$.payment.instruments[0]',
                )],
            default => [],
        };
    }

    private static function latestTransaction(OrderEntity $order): ?OrderTransactionEntity
    {
        $transactions = $order->getTransactions()?->getElements() ?? [];
        usort(
            $transactions,
            static fn (OrderTransactionEntity $a, OrderTransactionEntity $b): int => $b->getCreatedAt() <=> $a->getCreatedAt(),
        );

        return $transactions[0] ?? null;
    }
}
