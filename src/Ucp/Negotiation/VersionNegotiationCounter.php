<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Negotiation;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Ucp\Sdk\Event\VersionNegotiationObservedEvent;

/**
 * Counts which UCP versions the agents talking to this shop actually speak.
 *
 * The plugin serves one UCP version, the one the linked SDK serves. Whether that costs a shop
 * traffic depends on which version the *agents* pin, and no public source records that; the
 * only place it can be seen is here, on every request, where the SDK reports the version the
 * agent's profile named and whether it was accepted. This listener keeps that from being thrown
 * away. The SDK's version-support policy is revisited on these counts (SDK
 * `docs/ucp-version-support-policy.md`).
 *
 * Counters only. The agent's profile URI is reduced to its host before anything is recorded,
 * and no request content is touched. Counts are accumulated per request and written as one
 * log record per distinct (observed version, served version, outcome, profile host) at kernel
 * terminate, at `info` level on the default channel. A production monolog setup that keeps
 * only errors drops them; `docs/ucp-version-support.md` shows the handler that keeps them.
 *
 * The event class is newer than SDK `0.0.6`. Against an SDK release without it the subscription
 * is inert: naming a class with `::class` does not load it, and nothing dispatches the event, so
 * the counters stay empty and nothing is logged.
 *
 * @internal
 */
#[Package('framework')]
final class VersionNegotiationCounter implements EventSubscriberInterface
{
    public const LOG_MESSAGE = 'UCP version negotiation observed';

    /**
     * @var array<string, array{observed_version: string, served_version: string, outcome: string, profile_host: string, count: int}>
     */
    private array $counters = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            VersionNegotiationObservedEvent::class => 'onVersionNegotiationObserved',
            KernelEvents::TERMINATE => 'flush',
        ];
    }

    public function onVersionNegotiationObserved(VersionNegotiationObservedEvent $event): void
    {
        $profileHost = self::host($event->getAgentProfileUri());
        $key = implode('|', [$event->getObservedVersion(), $event->getServedVersion(), $event->getOutcome()->value, $profileHost]);

        $this->counters[$key] ??= [
            'observed_version' => $event->getObservedVersion(),
            'served_version' => $event->getServedVersion(),
            'outcome' => $event->getOutcome()->value,
            'profile_host' => $profileHost,
            'count' => 0,
        ];
        ++$this->counters[$key]['count'];
    }

    public function flush(): void
    {
        foreach ($this->counters as $counter) {
            $this->logger->info(self::LOG_MESSAGE, $counter);
        }

        $this->counters = [];
    }

    private static function host(?string $profileUri): string
    {
        $host = null === $profileUri ? null : parse_url($profileUri, \PHP_URL_HOST);

        return \is_string($host) && '' !== $host ? strtolower($host) : 'unknown';
    }
}
