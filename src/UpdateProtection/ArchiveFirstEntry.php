<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\UpdateProtection;

use Shopware\Core\Framework\Log\Package;

/**
 * Reads the archive's first entry the way core's extractor does to name the directory it replaces.
 *
 * @internal
 */
#[Package('framework')]
final class ArchiveFirstEntry
{
    public static function isInDirectory(string $archivePath, string $directory): bool
    {
        $archive = new \ZipArchive();

        if (true !== $archive->open($archivePath, \ZipArchive::RDONLY)) {
            return false;
        }

        $entry = $archive->statIndex(0);
        $archive->close();

        return \is_array($entry) && $directory === explode('/', $entry['name'])[0];
    }
}
