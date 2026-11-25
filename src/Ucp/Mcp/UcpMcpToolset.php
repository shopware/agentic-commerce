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

    /** Literals, so this file still loads on lines without the classes. */
    private const TOOL_RESULT_CLASS = 'Shopware\\Core\\Framework\\Mcp\\Result\\McpToolResult';

    private const TOOL_RESULT_RENDERER_CLASS = 'Shopware\\Core\\Framework\\Mcp\\Result\\McpToolResultRenderer';

    public static function coreSupportsConnectTimeToolsets(): bool
    {
        return \defined(self::ALL_TOOLSETS_CONSTANT);
    }

    /**
     * Whether core can take the `McpToolResult` the UCP tools return (from 6.7.16.0). Before that the
     * tools would have to return the `{"success": …}` string that core deprecates, so they are not
     * registered at all and the MCP transport is not offered.
     *
     * Both classes arrived in 6.7.16.0. The renderer, which turns the result into the MCP result,
     * is checked too because the tests declare a `McpToolResult` stub on older lanes, and that
     * stub must not make a kernel booted in the same process register the tools.
     */
    public static function coreSupportsToolResults(): bool
    {
        return class_exists(self::TOOL_RESULT_CLASS) && class_exists(self::TOOL_RESULT_RENDERER_CLASS);
    }
}
