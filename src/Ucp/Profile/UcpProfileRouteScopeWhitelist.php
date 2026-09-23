<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Profile;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\RouteScopeWhitelistInterface;
use Ucp\Sdk\Symfony\Controller\ProfileController;

/**
 * Lets discovery resolve the sales-channel domain from the request URI when Shopware has not
 * marked a root `/.well-known/ucp` request as a storefront request yet.
 *
 * @internal
 */
#[Package('discovery')]
final class UcpProfileRouteScopeWhitelist implements RouteScopeWhitelistInterface
{
    public function applies(string $controllerClass): bool
    {
        return ProfileController::class === $controllerClass;
    }
}
