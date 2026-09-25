<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp;

use Shopware\Core\Framework\Log\Package;

/**
 * The toolset the UCP MCP tools belong to on the Store API MCP server.
 *
 * Core reserves its `discovery` group for its own meta-tools: anything in it is advertised on every
 * `/store-api/_mcp` connection. The UCP tools therefore sit in their own toolset, and `/ucp/mcp` pins
 * that toolset at connect time (`?toolsets=ucp`, shopware/shopware#20509), so a UCP agent still sees
 * them on its first `tools/list` while a plain Store API connection does not.
 *
 * @internal
 */
#[Package('checkout')]
final class UcpMcpToolset
{
    public const NAME = 'ucp';

    /** Mirrors McpRequestedToolsetResolver::QUERY_PARAMETER, which core reads from the main request. */
    public const QUERY_PARAMETER = 'toolsets';

    /** Present from the Shopware release that supports connect-time toolset selection (6.7.15.0). */
    private const TOOLSET_RESOLVER_CLASS = 'Shopware\\Core\\Framework\\Mcp\\McpRequestedToolsetResolver';

    public static function coreSupportsConnectTimeToolsets(): bool
    {
        return class_exists(self::TOOLSET_RESOLVER_CLASS);
    }
}
