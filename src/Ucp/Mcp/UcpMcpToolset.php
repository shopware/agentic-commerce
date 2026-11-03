<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp;

use Shopware\Core\Framework\Log\Package;

/**
 * The UCP tools must not join core's `discovery` group, because core advertises that group on every
 * Store API connection.
 *
 * @internal
 */
#[Package('checkout')]
final class UcpMcpToolset
{
    public const NAME = 'ucp';

    /** A literal, because core's `McpToolsetRegistry::QUERY_PARAMETER` is missing before 6.7.16.0. */
    public const QUERY_PARAMETER = 'toolsets';

    private const ALL_TOOLSETS_CONSTANT = 'Shopware\\Core\\Framework\\Mcp\\McpToolsetRegistry::ALL_TOOLSETS';

    public static function coreSupportsConnectTimeToolsets(): bool
    {
        return \defined(self::ALL_TOOLSETS_CONSTANT);
    }
}
