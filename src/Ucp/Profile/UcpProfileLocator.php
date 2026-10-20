<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Profile;

use Shopware\Core\Framework\Log\Package;

/**
 * Well-known URIs exist only at the root of an origin (RFC 8615, section 3), and UCP requires a
 * business profile URL to point to `/.well-known/ucp`, so a domain with a path publishes no profile.
 *
 * @internal
 */
#[Package('framework')]
final class UcpProfileLocator
{
    public const PROFILE_PATH = '/.well-known/ucp';

    /**
     * An empty domain URL stands for the requested host and yields the root-relative profile path.
     */
    public function profileUrl(string $domainUrl): ?string
    {
        if ('' !== rtrim((string) parse_url($domainUrl, \PHP_URL_PATH), '/')) {
            return null;
        }

        return rtrim($domainUrl, '/').self::PROFILE_PATH;
    }
}
