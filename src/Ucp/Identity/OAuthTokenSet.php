<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

/** @internal */
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
