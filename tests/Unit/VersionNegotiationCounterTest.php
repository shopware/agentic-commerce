<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Negotiation\VersionNegotiationCounter;
use Symfony\Component\HttpKernel\KernelEvents;
use Ucp\Sdk\Enum\VersionNegotiationOutcome;
use Ucp\Sdk\Event\VersionNegotiationObservedEvent;

/** @internal */
#[CoversClass(VersionNegotiationCounter::class)]
final class VersionNegotiationCounterTest extends TestCase
{
    #[Test]
    public function testItWritesOneRecordPerDistinctObservationAtTerminate(): void
    {
        $logger = new CollectingLogger();
        $counter = new VersionNegotiationCounter($logger);

        $counter->onVersionNegotiationObserved(new VersionNegotiationObservedEvent('2026-04-08', '2026-08-25', 'https://Agent.Example/.well-known/ucp', VersionNegotiationOutcome::Rejected));
        $counter->onVersionNegotiationObserved(new VersionNegotiationObservedEvent('2026-04-08', '2026-08-25', 'https://agent.example/.well-known/ucp', VersionNegotiationOutcome::Rejected));
        $counter->onVersionNegotiationObserved(new VersionNegotiationObservedEvent('2026-08-25', '2026-08-25', 'https://agent.example/.well-known/ucp', VersionNegotiationOutcome::Accepted));

        // Read into a local first: asserting the property itself is empty narrows it to array{}
        // for the rest of the method, and PHPStan cannot see that flush() refills it.
        $beforeFlush = $logger->records;
        self::assertSame([], $beforeFlush, 'Nothing is written before the request terminates.');

        $counter->flush();

        self::assertCount(2, $logger->records);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame(VersionNegotiationCounter::LOG_MESSAGE, $logger->records[0]['message']);
        self::assertSame([
            'observed_version' => '2026-04-08',
            'served_version' => '2026-08-25',
            'outcome' => 'rejected',
            'profile_host' => 'agent.example',
            'count' => 2,
        ], $logger->records[0]['context']);
        self::assertSame([
            'observed_version' => '2026-08-25',
            'served_version' => '2026-08-25',
            'outcome' => 'accepted',
            'profile_host' => 'agent.example',
            'count' => 1,
        ], $logger->records[1]['context']);

        $counter->flush();

        self::assertCount(2, $logger->records, 'Flushing resets the counters; nothing is written twice.');
    }

    #[Test]
    public function testItRecordsOnlyTheHostOfTheAgentProfile(): void
    {
        $logger = new CollectingLogger();
        $counter = new VersionNegotiationCounter($logger);

        $counter->onVersionNegotiationObserved(new VersionNegotiationObservedEvent('2026-08-25', '2026-08-25', 'https://agent.example/tenants/4711/.well-known/ucp?key=secret', VersionNegotiationOutcome::Accepted));
        $counter->onVersionNegotiationObserved(new VersionNegotiationObservedEvent('2026-08-25', '2026-08-25', null, VersionNegotiationOutcome::Accepted));
        $counter->flush();

        self::assertSame('agent.example', $logger->records[0]['context']['profile_host']);
        self::assertStringNotContainsString('4711', json_encode($logger->records, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret', json_encode($logger->records, \JSON_THROW_ON_ERROR));
        self::assertSame('unknown', $logger->records[1]['context']['profile_host']);
    }

    #[Test]
    public function testItListensToTheSdkEventAndFlushesOnKernelTerminate(): void
    {
        self::assertSame([
            VersionNegotiationObservedEvent::class => 'onVersionNegotiationObserved',
            KernelEvents::TERMINATE => 'flush',
        ], VersionNegotiationCounter::getSubscribedEvents());
    }
}
