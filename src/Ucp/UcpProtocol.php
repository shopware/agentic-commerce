<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp;

final class UcpProtocol
{
    public const VERSION = '2026-04-08';

    public static function specificationUrl(string $capability): string
    {
        return sprintf('https://ucp.dev/specification/%s/', $capability);
    }

    public static function schemaUrl(string $capability): string
    {
        return sprintf('https://ucp.dev/schemas/shopping/%s.json', $capability);
    }
}
