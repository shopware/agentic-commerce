<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

final readonly class OAuthTokenSet
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
        public string $scope,
    ) {
    }
}
