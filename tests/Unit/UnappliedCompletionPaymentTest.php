<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\UnappliedCompletionPayment;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * Pins the default: change nothing about the order, and say so.
 *
 * The point of this class is that installing the seam moves no money. It keeps charging the
 * sales channel default exactly as before, so the only observable difference is that an
 * ignored instrument now leaves a trace instead of disappearing.
 */
#[CoversClass(UnappliedCompletionPayment::class)]
final class UnappliedCompletionPaymentTest extends TestCase
{
    #[Test]
    public function itReturnsTheContextUnchangedSoNoOrderChanges(): void
    {
        $context = $this->salesChannelContext();

        $result = (new UnappliedCompletionPayment())->apply(
            $this->instrument(),
            $context,
            new RequestContext('shop.example'),
        );

        self::assertSame($context, $result);
    }

    /**
     * The warning is the whole contribution. Without it an agent's instrument is validated,
     * discarded and never mentioned, which is why nobody noticed the gap for so long -- so
     * it has to name the handler that was asked for, not just that something happened.
     */
    #[Test]
    public function itWarnsNamingTheHandlerTheAgentAskedFor(): void
    {
        $logger = $this->collectingLogger();

        (new UnappliedCompletionPayment($logger))->apply(
            $this->instrument(),
            $this->salesChannelContext(),
            new RequestContext('shop.example'),
        );

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('com.shopware.invoice', $logger->records[0]['context']['handler_id']);
        self::assertStringContainsString('CompletionPaymentApplierInterface', $logger->records[0]['message']);
        self::assertStringContainsString('docs/completion-payment.md', $logger->records[0]['message']);
    }

    /**
     * An agent that sent no instrument is not misconfigured, so warning about it would train
     * operators to ignore the line that matters.
     */
    #[Test]
    public function itStaysSilentWhenTheAgentSentNoInstrument(): void
    {
        $logger = $this->collectingLogger();

        (new UnappliedCompletionPayment($logger))->apply(
            null,
            $this->salesChannelContext(),
            new RequestContext('shop.example'),
        );

        self::assertSame([], $logger->records);
    }

    /**
     * The bundle wires the logger with nullOnInvalid(), so an application without one must
     * not fail on the path whose only job was to complain.
     */
    #[Test]
    public function itDoesNothingHarmfulWithoutALogger(): void
    {
        $context = $this->salesChannelContext();

        $result = (new UnappliedCompletionPayment())->apply(
            $this->instrument(),
            $context,
            new RequestContext('shop.example'),
        );

        self::assertSame($context, $result);
    }

    private function instrument(): PaymentInstrument
    {
        return new PaymentInstrument('card', 'com.shopware.invoice', ['payment_method_id' => 'pm-1']);
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-1');

        return $context;
    }

    /**
     * Anonymous so the file holds one class: the repository forbids a second, and a
     * throwaway recorder does not need a name.
     *
     * @return AbstractLogger&object{records: list<array{level: string, message: string, context: array<string, mixed>}>}
     */
    private function collectingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
