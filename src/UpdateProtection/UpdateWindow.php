<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\UpdateProtection;

use Shopware\Core\Framework\Log\Package;

/**
 * Open while the running request or command replaces the plugin's files and nothing of the plugin
 * has to run for it afterwards. Listeners that write to the database or to files return early then.
 *
 * @internal
 */
#[Package('framework')]
final class UpdateWindow
{
    private static bool $isOpen = false;

    public static function open(): void
    {
        self::$isOpen = true;
    }

    public static function close(): void
    {
        self::$isOpen = false;
    }

    public static function isOpen(): bool
    {
        return self::$isOpen;
    }
}
