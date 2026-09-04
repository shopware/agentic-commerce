<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp;

/** @internal */
final class UcpProtocol
{
    public const VERSION = '2026-08-25';

    public static function specificationUrl(string $capability): string
    {
        return \sprintf('https://ucp.dev/specification/%s/', $capability);
    }

    public static function schemaUrl(string $capability, string $category = 'shopping'): string
    {
        return \sprintf('https://ucp.dev/%s/schemas/%s/%s.json', self::VERSION, $category, $capability);
    }
}
