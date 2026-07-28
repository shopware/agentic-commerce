<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

/**
 * A validated OAuth access token: which customer (`subject`) the bearer may act
 * for, on behalf of which platform, and with which scopes.
 *
 * @internal
 */
final class OAuthAccessTokenInfo
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $salesChannelId,
        public readonly string $clientId,
        public readonly string $subject,
        public readonly array $scopes,
    ) {
    }

    public function hasScope(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }
}
