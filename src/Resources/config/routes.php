<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use RuntimeException as RouteRuntimeException;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Swag\AgenticCommerce\Ucp\Quote\QuoteBackendFeature;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Ucp/Admin/Api/', 'attribute');
    $routes->import('../../Ucp/Mcp/Api/', 'attribute');

    // Test-only webhook-capture routes (issue #53): never registered in prod, matching the
    // service-graph gate in services.php.
    if ('prod' !== EnvironmentHelper::getVariable('APP_ENV', 'prod')) {
        $routes->import('../../Ucp/Test/Api/', 'attribute');
    }

    if (!CoreSalesChannelFileFeature::isAvailableByClass()) {
        $routes->import('../../AgenticFiles/Fallback/FallbackAgenticFileController.php', 'attribute');
    }

    // Vendor capability com.shopware.quote: only routable when the commercial B2B
    // quote backend is installed, matching the service-graph gate in services.php.
    if (QuoteBackendFeature::isAvailableByClass()) {
        $routes->import('../../Ucp/Quote/Controller/', 'attribute');
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
