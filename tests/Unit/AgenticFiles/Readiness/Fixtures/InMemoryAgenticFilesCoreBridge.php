<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\AgenticFiles\Readiness\Fixtures;

use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;

/**
 * Holds `sales_channel_file` rows in memory and, like the database, matches file names case-insensitively.
 *
 * @internal
 */
final class InMemoryAgenticFilesCoreBridge implements AgenticFilesCoreBridgeInterface
{
    /**
     * @var array<string, array<string, bool>> enabled flag per sales channel and stored file name
     */
    public array $rows = [];

    /**
     * @var list<string>
     */
    public array $ucpCalls = [];

    public function __construct(public bool $supportsCoreFiles = true)
    {
    }

    public function enableForSalesChannel(string $salesChannelId): void
    {
        $this->ucpCalls[] = 'enableForSalesChannel '.$salesChannelId;
    }

    public function syncActiveUcpSalesChannels(): void
    {
        $this->ucpCalls[] = 'syncActiveUcpSalesChannels';
    }

    public function supportsCoreFiles(): bool
    {
        return $this->supportsCoreFiles;
    }

    public function fileStates(array $salesChannelIds, array $fileNames): array
    {
        $states = [];
        foreach ($salesChannelIds as $salesChannelId) {
            foreach ($fileNames as $fileName) {
                $enabled = $this->row($salesChannelId, $fileName);
                if (null !== $enabled) {
                    $states[$salesChannelId][$fileName] = $enabled;
                }
            }
        }

        return $states;
    }

    public function insertMissingFiles(string $salesChannelId, array $fileNames): array
    {
        $inserted = [];
        foreach ($fileNames as $fileName) {
            if (null === $this->row($salesChannelId, $fileName)) {
                $this->rows[$salesChannelId][$fileName] = true;
                $inserted[] = $fileName;
            }
        }

        return $inserted;
    }

    private function row(string $salesChannelId, string $fileName): ?bool
    {
        foreach ($this->rows[$salesChannelId] ?? [] as $storedFileName => $enabled) {
            if (0 === strcasecmp($storedFileName, $fileName)) {
                return $enabled;
            }
        }

        return null;
    }
}
