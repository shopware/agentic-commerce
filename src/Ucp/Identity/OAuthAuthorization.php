<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

/** @internal */
final class OAuthAuthorization
{
    public function __construct(
        public readonly string $salesChannelId,
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly string $subject,
        public readonly string $scope,
        public readonly string $codeChallenge,
        public readonly string $codeChallengeMethod,
    ) {
    }
}
