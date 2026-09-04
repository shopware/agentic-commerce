<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('framework')]
final class OAuthTokenSet
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresIn,
        public readonly string $scope,
    ) {
    }
}
