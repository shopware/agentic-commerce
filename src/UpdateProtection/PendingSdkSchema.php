<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\UpdateProtection;

use Shopware\Core\Framework\Log\Package;

/**
 * Remembers that an update left the SDK schema to a later process. An update through `store:download` or the
 * Administration runs `update()` with the old SDK classes still loaded, so it applies the old schema definition.
 *
 * The SDK's schema diff drops every column and index it does not know. A container build without the marker therefore
 * never touches the schema, so a rollback or a node still running an older SDK cannot drop what a newer SDK added.
 *
 * It never throws, because it runs while the container is compiled and in `update()`, and uses no SDK class, because
 * it may load while the SDK is unusable.
 *
 * @internal
 */
#[Package('framework')]
final class PendingSdkSchema
{
    // Under `var/`, not `var/cache/`, which `cache:clear` empties.
    private const MARKER_FILE = '/var/swag-agentic-commerce-sdk-schema-pending';

    public static function markIn(string $projectDir): void
    {
        $directory = $projectDir.'/var';

        if (!is_dir($directory)) {
            @mkdir($directory, 0o777, true);
        }

        if (false === @file_put_contents($projectDir.self::MARKER_FILE, '')) {
            error_log('[SwagAgenticCommerce] The marker for the pending SDK schema could not be written to '.$directory);
        }
    }

    /**
     * Keeps the marker for the next container build when the schema was not applied.
     *
     * @param \Closure(): bool $applySchema returns false when nothing could be applied, for example without a database
     */
    public static function applyWhenMarkedIn(string $projectDir, \Closure $applySchema): void
    {
        $markerPath = $projectDir.self::MARKER_FILE;

        if (!is_file($markerPath)) {
            return;
        }

        try {
            if (!$applySchema()) {
                return;
            }
        } catch (\Throwable $exception) {
            error_log(\sprintf('[SwagAgenticCommerce] The SDK schema could not be bootstrapped while building the container: %s', $exception->getMessage()));

            return;
        }

        if (!@unlink($markerPath) && is_file($markerPath)) {
            error_log('[SwagAgenticCommerce] The marker for the pending SDK schema could not be removed: '.$markerPath);
        }
    }
}
