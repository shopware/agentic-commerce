<?php

declare(strict_types=1);

use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Ucp/Admin/Api/', 'attribute');
    $routes->import('../../Ucp/Mcp/Api/', 'attribute');
    $routes->import('../../Ucp/Test/Api/', 'attribute');

    $sdkRouteCandidates = [
        \dirname(__DIR__, 3).'/vendor/ucp-php-sdk/symfony-bundle/src/Resources/config/routes.php',
        \dirname(__DIR__, 3).'/../ucp-php-sdk/packages/symfony-bundle/src/Resources/config/routes.php',
        \dirname(__DIR__, 3).'/../../ucp-php-sdk/packages/symfony-bundle/src/Resources/config/routes.php',
    ];

    foreach ($sdkRouteCandidates as $sdkRoutes) {
        if (!is_file($sdkRoutes)) {
            continue;
        }

        $routes->import($sdkRoutes)->defaults([
            PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID],
            'auth_required' => false,
        ]);

        break;
    }
};
