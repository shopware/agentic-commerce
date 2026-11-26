<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentResult;
use Ucp\Sdk\Enum\CheckoutStatus;

/** @internal */
#[CoversClass(CompletionPaymentResult::class)]
final class CompletionPaymentResultTest extends TestCase
{
    private const REDIRECT_URL = 'https://psp.example/pay/1';

    #[Test]
    public function testACompletedSessionStaysCompletedWhileItsTransactionIsOpen(): void
    {
        $result = CompletionPaymentResult::ofPlacedOrder(
            ['status' => CheckoutStatus::Completed->value],
            $this->order($this->transaction(OrderTransactionStates::STATE_OPEN, '2026-11-01 10:00:00')),
        );

        self::assertSame(CheckoutStatus::Completed, $result->status);
        self::assertNull($result->redirectUrl);
    }

    #[Test]
    public function testAnEscalatedSessionWithAnOpenTransactionStillAwaitsTheBuyerAtTheStoredRedirect(): void
    {
        $result = CompletionPaymentResult::ofPlacedOrder(
            ['status' => CheckoutStatus::RequiresEscalation->value, CompletionPaymentResult::SESSION_REDIRECT_URL_KEY => self::REDIRECT_URL],
            $this->order($this->transaction(OrderTransactionStates::STATE_OPEN, '2026-11-01 10:00:00')),
        );

        self::assertSame(CheckoutStatus::RequiresEscalation, $result->status);
        self::assertSame(self::REDIRECT_URL, $result->redirectUrl);
    }

    #[Test]
    public function testAnEscalatedSessionWhoseTransactionIsPaidIsCompleted(): void
    {
        $result = CompletionPaymentResult::ofPlacedOrder(
            ['status' => CheckoutStatus::RequiresEscalation->value, CompletionPaymentResult::SESSION_REDIRECT_URL_KEY => self::REDIRECT_URL],
            $this->order($this->transaction(OrderTransactionStates::STATE_PAID, '2026-11-01 10:00:00')),
        );

        self::assertSame(CheckoutStatus::Completed, $result->status);
        self::assertNull($result->redirectUrl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failedTransactionStateProvider(): iterable
    {
        yield 'the payment provider declined' => [OrderTransactionStates::STATE_FAILED];
        yield 'the buyer cancelled at the payment provider' => [OrderTransactionStates::STATE_CANCELLED];
    }

    #[DataProvider('failedTransactionStateProvider')]
    #[Test]
    public function testAnEscalatedSessionWhoseTransactionEndedWithoutPaymentFailed(string $transactionState): void
    {
        $result = CompletionPaymentResult::ofPlacedOrder(
            ['status' => CheckoutStatus::RequiresEscalation->value, CompletionPaymentResult::SESSION_REDIRECT_URL_KEY => self::REDIRECT_URL],
            $this->order($this->transaction($transactionState, '2026-11-01 10:00:00')),
        );

        self::assertSame(CheckoutStatus::Incomplete, $result->status);
        self::assertNull($result->redirectUrl);
    }

    #[Test]
    public function testAnIncompleteSessionFailedEvenWithAnOpenTransaction(): void
    {
        $result = CompletionPaymentResult::ofPlacedOrder(
            ['status' => CheckoutStatus::Incomplete->value],
            $this->order($this->transaction(OrderTransactionStates::STATE_OPEN, '2026-11-01 10:00:00')),
        );

        self::assertSame(CheckoutStatus::Incomplete, $result->status);
    }

    #[Test]
    public function testTheNewestTransactionDecides(): void
    {
        $result = CompletionPaymentResult::ofPlacedOrder(
            ['status' => CheckoutStatus::RequiresEscalation->value, CompletionPaymentResult::SESSION_REDIRECT_URL_KEY => self::REDIRECT_URL],
            $this->order(
                $this->transaction(OrderTransactionStates::STATE_OPEN, '2026-11-01 10:05:00'),
                $this->transaction(OrderTransactionStates::STATE_FAILED, '2026-11-01 10:00:00'),
            ),
        );

        self::assertSame(CheckoutStatus::RequiresEscalation, $result->status);
        self::assertSame(self::REDIRECT_URL, $result->redirectUrl);
    }

    #[Test]
    public function testACompletedPaymentCarriesNoMessage(): void
    {
        self::assertSame([], CompletionPaymentResult::completed()->messages());
    }

    #[Test]
    public function testAPaymentAwaitingTheBuyerAsksForBuyerInput(): void
    {
        $messages = CompletionPaymentResult::awaitsBuyer(self::REDIRECT_URL)->messages();

        self::assertCount(1, $messages);
        self::assertSame('error', $messages[0]->type);
        self::assertSame('requires_buyer_input', $messages[0]->severity);
        self::assertSame('payment_requires_buyer_input', $messages[0]->code);
        self::assertSame('$.payment', $messages[0]->path);
    }

    #[Test]
    public function testAFailedPaymentIsRecoverable(): void
    {
        $messages = CompletionPaymentResult::failed()->messages();

        self::assertCount(1, $messages);
        self::assertSame('error', $messages[0]->type);
        self::assertSame('recoverable', $messages[0]->severity);
        self::assertSame('payment_failed', $messages[0]->code);
        self::assertSame('$.payment.instruments[0]', $messages[0]->path);
    }

    #[Test]
    public function testAPaymentAwaitingTheBuyerContinuesAtTheRedirect(): void
    {
        self::assertSame(self::REDIRECT_URL, CompletionPaymentResult::awaitsBuyer(self::REDIRECT_URL)->continueUrl('https://shop.example/checkout/1'));
    }

    #[Test]
    public function testOtherPaymentStatesKeepTheCheckoutContinueUrl(): void
    {
        self::assertSame('https://shop.example/checkout/1', CompletionPaymentResult::completed()->continueUrl('https://shop.example/checkout/1'));
        self::assertSame('https://shop.example/checkout/1', CompletionPaymentResult::failed()->continueUrl('https://shop.example/checkout/1'));
        self::assertNull(CompletionPaymentResult::failed()->continueUrl(null));
    }

    private function order(OrderTransactionEntity ...$transactions): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setTransactions(new OrderTransactionCollection($transactions));

        return $order;
    }

    private function transaction(string $state, string $createdAt): OrderTransactionEntity
    {
        $stateMachineState = new StateMachineStateEntity();
        $stateMachineState->setId(Uuid::randomHex());
        $stateMachineState->setTechnicalName($state);

        $transaction = new OrderTransactionEntity();
        $transaction->setId(Uuid::randomHex());
        $transaction->setCreatedAt(new \DateTimeImmutable($createdAt));
        $transaction->setStateMachineState($stateMachineState);

        return $transaction;
    }
}
