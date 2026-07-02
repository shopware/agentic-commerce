<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Shopware\Core\Framework\Context;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared sales-channel lookup for the UCP console commands: resolves the
 * --sales-channel option (accepting an id OR a name), offers an interactive
 * picker when it is omitted, and renders the discovery table. This spares
 * operators from having to hunt down a raw sales-channel id.
 *
 * @internal
 */
final class SalesChannelResolver
{
    public function __construct(private readonly SalesChannelViewProvider $salesChannelViewProvider)
    {
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $channel): array => [
                'id' => (string) ($channel['id'] ?? ''),
                'name' => (string) ($channel['name'] ?? ''),
            ],
            $this->salesChannelViewProvider->all(Context::createCLIContext()),
        );
    }

    public function renderTable(SymfonyStyle $io): void
    {
        $channels = $this->all();
        if ([] === $channels) {
            $io->warning('No sales channels found.');

            return;
        }

        $io->table(['Name', 'Id'], array_map(static fn (array $c): array => [$c['name'], $c['id']], $channels));
    }

    /**
     * Resolve the given option value (id or name) to a sales-channel id.
     *
     * Returns null for the global/default scope. Returns false (and prints the
     * reason plus the available channels) when the value cannot be resolved, so
     * the caller can abort with a non-zero exit code.
     */
    public function resolve(InputInterface $input, SymfonyStyle $io, ?string $value, bool $allowGlobal): string|false|null
    {
        $channels = $this->all();

        if (null !== $value && '' !== $value) {
            foreach ($channels as $channel) {
                if ($channel['id'] === $value) {
                    return $channel['id'];
                }
            }

            $matches = array_values(array_filter($channels, static fn (array $c): bool => 0 === strcasecmp($c['name'], $value)));
            if (1 === \count($matches)) {
                return $matches[0]['id'];
            }

            if (\count($matches) > 1) {
                $io->error(\sprintf('Sales channel name "%s" is ambiguous — pass the id instead.', $value));
            } else {
                $io->error(\sprintf('No sales channel matches "%s".', $value));
            }
            $this->renderTable($io);

            return false;
        }

        if (!$input->isInteractive()) {
            if ($allowGlobal) {
                return null;
            }

            $io->error('The --sales-channel option is required. Pass an id or name:');
            $this->renderTable($io);

            return false;
        }

        return $this->askForSalesChannel($io, $channels, $allowGlobal);
    }

    /**
     * @param list<array{id: string, name: string}> $channels
     */
    private function askForSalesChannel(SymfonyStyle $io, array $channels, bool $allowGlobal): ?string
    {
        $labelToId = [];
        $labels = [];

        if ($allowGlobal) {
            $global = 'Global / default (all channels)';
            $labels[] = $global;
            $labelToId[$global] = null;
        }

        foreach ($channels as $channel) {
            $label = \sprintf('%s  —  %s', $channel['name'], $channel['id']);
            $labels[] = $label;
            $labelToId[$label] = $channel['id'];
        }

        if ([] === $labels) {
            return null;
        }

        $selected = $io->choice('Select a sales channel', $labels, $labels[0]);

        return $labelToId[$selected] ?? null;
    }
}
