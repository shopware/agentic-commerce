<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

use Shopware\Core\Framework\Log\Package;

/**
 * Base class for extensions that add UCP OAuth scopes of their own.
 *
 * Extend it, return the scopes the extension's own UCP capability authorizes, and tag the
 * service with `swag_agentic_commerce.ucp.oauth_scope_provider`. The scopes are advertised in
 * `scopes_supported` on `/.well-known/oauth-authorization-server` and become grantable in an
 * authorization request; every scope nobody registered is still rejected.
 */
#[Package('framework')]
abstract class AbstractUcpOAuthScopeProvider
{
    /**
     * @return list<string>
     */
    abstract public function getScopes(): array;
}
