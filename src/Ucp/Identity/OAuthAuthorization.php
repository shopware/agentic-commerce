<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

final readonly class OAuthAuthorization
{
    public function __construct(
        public string $salesChannelId,
        public string $clientId,
        public string $redirectUri,
        public string $subject,
        public string $scope,
        public string $codeChallenge,
        public string $codeChallengeMethod,
    ) {
    }
}
