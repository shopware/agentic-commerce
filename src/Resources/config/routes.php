<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use RuntimeException as RouteRuntimeException;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

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

    $sdkBundlePath = InstalledVersions::getInstallPath('ucp-php-sdk/symfony-bundle');
    $sdkRoutes = \is_string($sdkBundlePath) ? $sdkBundlePath.'/src/Resources/config/routes.php' : null;
    if (!\is_string($sdkRoutes) || !is_file($sdkRoutes)) {
        throw new RouteRuntimeException('Unable to load UCP SDK routes from the Composer-installed Symfony bundle.');
    }

    $routes->import($sdkRoutes)->defaults([
        PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID],
        'auth_required' => false,
    ]);
};
