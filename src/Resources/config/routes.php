<?php

declare(strict_types=1);

use RuntimeException as RouteRuntimeException;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Ucp\Sdk\Symfony\UcpSdkBundle;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Ucp/Admin/Api/', 'attribute');
    $routes->import('../../Ucp/Mcp/Api/', 'attribute');

    // Test-only webhook-capture routes (issue #53): never registered in prod, matching the
    // service-graph gate in services.php.
    if ('prod' !== EnvironmentHelper::getVariable('APP_ENV', 'prod')) {
        $routes->import('../../Ucp/Test/Api/', 'attribute');
    }

    $routes->import('../../AgenticFiles/ApiCatalog/ApiCatalogController.php', 'attribute');

    if (!CoreSalesChannelFileFeature::isAvailableByClass()) {
        $routes->import('../../AgenticFiles/Fallback/FallbackAgenticFileController.php', 'attribute');
    }

    // Match the bundle actually loaded by PHP. A host Composer registry can point
    // at another SDK copy even when this installation uses the bundled runtime.
    $sdkRoutes = (new UcpSdkBundle())->getPath().'/Resources/config/routes.php';
    if (!is_file($sdkRoutes)) {
        throw new RouteRuntimeException('Unable to load UCP SDK routes from the active Symfony bundle.');
    }

    $routes->import($sdkRoutes)->defaults([
        PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID],
        'auth_required' => false,
    ]);
};
