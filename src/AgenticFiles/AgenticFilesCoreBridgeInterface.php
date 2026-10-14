<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('discovery')]
interface AgenticFilesCoreBridgeInterface
{
    public function enableForSalesChannel(string $salesChannelId): void;

    public function syncActiveUcpSalesChannels(): void;

    public function supportsCoreFiles(): bool;

    /**
     * @param list<string> $salesChannelIds
     * @param list<string> $fileNames
     *
     * @return array<string, array<string, bool>> the `enabled` flag per sales channel and requested file name; no entry means no row
     */
    public function fileStates(array $salesChannelIds, array $fileNames): array;

    /**
     * Creates enabled rows for the given files that have none yet; an existing row is never changed.
     *
     * @param list<string> $fileNames
     *
     * @return list<string> the file names a row was created for
     */
    public function insertMissingFiles(string $salesChannelId, array $fileNames): array;
}
