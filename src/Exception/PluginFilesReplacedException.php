<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Exception;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('framework')]
final class PluginFilesReplacedException extends \RuntimeException
{
    /**
     * PHP cannot unload the classes it already loaded, so a lifecycle call that runs old code against the new files has to stop.
     */
    public static function whileTheProcessWasRunning(string $runningVersion, ?string $versionOnDisk): self
    {
        return new self(\sprintf(
            'The files of SwagAgenticCommerce were replaced by version %s while this process was running version %s. '
            .'Run the lifecycle command again in a new process, for example `bin/console plugin:update SwagAgenticCommerce`. '
            .'Shopware may have deactivated the extension because of this error, so activate it again with '
            .'`bin/console plugin:activate SwagAgenticCommerce`.',
            $versionOnDisk ?? 'unknown',
            $runningVersion,
        ));
    }
}
