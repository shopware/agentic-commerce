<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Routing;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\RouteScopeWhitelistInterface;
use Swag\AgenticCommerce\Ucp\Mcp\Api\UcpMcpProxyController;

#[Package('checkout')]
final class StoreApiMcpRouteScopeWhitelist implements RouteScopeWhitelistInterface
{
    private const STORE_API_MCP_CONTROLLER = 'Shopware\\Core\\Framework\\Mcp\\Controller\\StoreApiMcpServerController';

    public function applies(string $controllerClass): bool
    {
        return self::STORE_API_MCP_CONTROLLER === $controllerClass
            || UcpMcpProxyController::class === $controllerClass;
    }
}
