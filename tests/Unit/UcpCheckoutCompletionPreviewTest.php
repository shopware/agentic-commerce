<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentGate;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCompletionPreview;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class UcpCheckoutCompletionPreviewTest extends TestCase
{
    #[Test]
    public function testReadyForCompleteHasNoBlockers(): void
    {
        self::assertSame([], (new UcpCheckoutCompletionPreview())->blockers('ready_for_complete'));
    }

    #[Test]
    public function testAlreadyCompletedHasNoBlockersBecauseCompletingReplaysTheExistingOrder(): void
    {
        self::assertSame([], (new UcpCheckoutCompletionPreview())->blockers('completed'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blockedStatusProvider(): iterable
    {
        yield 'incomplete' => ['incomplete', 'Checkout is incomplete: finish it with update_checkout before completing.'];
        yield 'requires escalation' => ['requires_escalation', 'Checkout requires escalation and cannot be completed by an agent.'];
        yield 'complete in progress' => ['complete_in_progress', 'Another completion for this checkout is already in flight; retry once it finishes.'];
        yield 'canceled' => ['canceled', 'Checkout is canceled and can no longer be completed.'];
    }

    #[DataProvider('blockedStatusProvider')]
    #[Test]
    public function testBlockedStatusesExplainWhyACommitWouldNotPlaceAnOrder(string $status, string $expectedBlocker): void
    {
        self::assertSame([$expectedBlocker], (new UcpCheckoutCompletionPreview())->blockers($status));
    }

    #[Test]
    public function testAPaymentRefusalBlocksACommitEvenWhenTheCheckoutIsReady(): void
    {
        self::assertSame(
            ['Completing a checkout needs exactly one payment instrument, 0 given.'],
            (new UcpCheckoutCompletionPreview())->blockers('ready_for_complete', $this->paymentRefusal()),
        );
    }

    #[Test]
    public function testAPaymentRefusalIsListedAfterTheStatusBlocker(): void
    {
        self::assertSame(
            [
                'Checkout is incomplete: finish it with update_checkout before completing.',
                'Completing a checkout needs exactly one payment instrument, 0 given.',
            ],
            (new UcpCheckoutCompletionPreview())->blockers('incomplete', $this->paymentRefusal()),
        );
    }

    #[Test]
    public function testAPaymentRefusalDoesNotBlockAnAlreadyCompletedCheckoutBecauseItIsReplayedBeforePayment(): void
    {
        self::assertSame([], (new UcpCheckoutCompletionPreview())->blockers('completed', $this->paymentRefusal()));
    }

    #[Test]
    public function testAMissingRuntimeConfigurationIsReportedAsAPaymentBlockerInsteadOfFailingThePreview(): void
    {
        $preview = new UcpCheckoutCompletionPreview();
        $gate = new CompletionPaymentGate(static function (): never {
            throw new \LogicException('The profile must not be built without a runtime configuration.');
        });

        $refusal = $preview->paymentRefusal($gate, ['com.shopware.invoice'], new RequestContext('shop.example'));

        self::assertNotNull($refusal);
        self::assertSame(UcpConfigException::RUNTIME_CONFIGURATION_MISSING, $refusal->code);
        self::assertSame(
            [$refusal->content],
            $preview->blockers('ready_for_complete', $refusal),
        );
    }

    #[Test]
    public function testAGateRefusalIsPassedThroughAsItIs(): void
    {
        $gate = new CompletionPaymentGate(static function (): never {
            throw new \LogicException('The profile must not be built for a wrong instrument count.');
        });

        $refusal = (new UcpCheckoutCompletionPreview())->paymentRefusal($gate, [], new RequestContext('shop.example'));

        self::assertSame('payment_failed', $refusal?->code);
    }

    #[Test]
    public function testNoPaymentRefusalWhenTheGateLetsTheCompletionGo(): void
    {
        $profileBuilder = RecordingProfileBuilder::advertising('com.shopware.invoice');
        $gate = new CompletionPaymentGate(static fn (): RecordingProfileBuilder => $profileBuilder);
        $context = new RequestContext('shop.example', runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example'));

        self::assertNull((new UcpCheckoutCompletionPreview())->paymentRefusal($gate, ['com.shopware.invoice'], $context));
    }

    #[Test]
    public function testEveryCheckoutStatusIsAccountedFor(): void
    {
        $preview = new UcpCheckoutCompletionPreview();

        foreach (CheckoutStatus::cases() as $status) {
            self::assertStringNotContainsString(
                'is not a state this tool knows how to complete',
                implode(' ', $preview->blockers($status->value)),
                \sprintf('CheckoutStatus::%s fell through to the unknown-status branch.', $status->name),
            );
        }
    }

    #[Test]
    public function testEnumInstancesAreAcceptedAsWellAsTheirValues(): void
    {
        $preview = new UcpCheckoutCompletionPreview();

        self::assertSame($preview->blockers('canceled'), $preview->blockers(CheckoutStatus::Canceled));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function unknownStatusProvider(): iterable
    {
        yield 'missing status' => [null, 'Checkout status "null" is not a state this tool knows how to complete.'];
        yield 'unrecognised string' => ['refunded', 'Checkout status "refunded" is not a state this tool knows how to complete.'];
        yield 'wrong type' => [42, 'Checkout status "int" is not a state this tool knows how to complete.'];
    }

    #[DataProvider('unknownStatusProvider')]
    #[Test]
    public function testAnUnknownStatusBlocksRatherThanSilentlyAllowingACommit(mixed $status, string $expectedBlocker): void
    {
        self::assertSame([$expectedBlocker], (new UcpCheckoutCompletionPreview())->blockers($status));
    }

    private function paymentRefusal(): Message
    {
        return new Message('error', 'Completing a checkout needs exactly one payment instrument, 0 given.', 'recoverable', 'payment_failed', '$.payment.instruments');
    }
}
