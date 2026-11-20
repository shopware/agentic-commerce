<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\DependencyInjection;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCartCancelTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCartCreateTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCartGetTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCartUpdateTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCatalogLookupTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCatalogSearchTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCancelTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCompleteTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCreateTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutGetTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutUpdateTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpDiscountApplyTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpOrderGetTool;
use Swag\AgenticCommerce\Ucp\Mcp\UcpMcpToolset;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

/**
 * Registers the UCP Store API MCP tools, but only where core takes the `McpToolResult` they return
 * (6.7.16.0 and later). On 6.7.12 to 6.7.15 the tools are not offered at all.
 *
 * The tools are excluded from the service glob in services.php, so this is their only registration:
 * autoconfiguration tags any `#[McpTool]` class with `mcp.tool`, and a globbed tool would reach
 * core's Admin API server on the lines this skips.
 *
 * @see docs/shopware-version-differences.md
 *
 * @internal
 */
#[Package('checkout')]
final class UcpMcpToolRegistration
{
    public const STORE_API_TOOL_TAG = 'shopware.store_api_mcp.tool';

    public const TOOLS = [
        UcpCatalogSearchTool::class,
        UcpCatalogLookupTool::class,
        UcpCartCreateTool::class,
        UcpCartGetTool::class,
        UcpCartUpdateTool::class,
        UcpCartCancelTool::class,
        UcpDiscountApplyTool::class,
        UcpCheckoutCreateTool::class,
        UcpCheckoutGetTool::class,
        UcpCheckoutUpdateTool::class,
        UcpCheckoutCompleteTool::class,
        UcpCheckoutCancelTool::class,
        UcpOrderGetTool::class,
    ];

    public static function register(ServicesConfigurator $services, ?bool $coreSupportsToolResults = null): void
    {
        if (!($coreSupportsToolResults ?? UcpMcpToolset::coreSupportsToolResults())) {
            return;
        }

        foreach (self::TOOLS as $tool) {
            $services->set($tool)->tag(self::STORE_API_TOOL_TAG);
        }
    }
}
