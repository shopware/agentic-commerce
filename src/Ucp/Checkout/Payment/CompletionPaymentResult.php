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
 * `Completed`: nothing is left for the agent to do. `RequiresEscalation`: the buyer has to finish
 * the payment at `$redirectUrl`. `Incomplete`: the payment failed and the agent may complete again.
 *
 * @internal
 */
final class CompletionPaymentResult
{
    public const SESSION_REDIRECT_URL_KEY = 'paymentRedirectUrl';

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

    public static function failed(): self
    {
        return new self(CheckoutStatus::Incomplete);
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
        if (\in_array($transactionState, self::FAILED_STATES, true)) {
            return self::failed();
        }

        if (CheckoutStatus::RequiresEscalation->value !== $sessionStatus) {
            return self::failed();
        }

        $redirectUrl = $sessionMetadata[self::SESSION_REDIRECT_URL_KEY] ?? null;
        if (\in_array($transactionState, self::AWAITING_STATES, true) && \is_string($redirectUrl) && '' !== $redirectUrl) {
            return self::awaitsBuyer($redirectUrl);
        }

        return self::completed();
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
            CheckoutStatus::Incomplete => [new Message(
                'error',
                'The payment failed. The order is kept. Complete the checkout again to pay it, with the same or another payment instrument.',
                'recoverable',
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
