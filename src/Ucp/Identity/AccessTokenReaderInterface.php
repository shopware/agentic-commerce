<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

/**
 * Read side of the OAuth access-token store.
 *
 * The resource server only needs to resolve a presented token, never to issue or
 * revoke one, so it depends on this narrow port instead of the whole store.
 *
 * @internal
 */
interface AccessTokenReaderInterface
{
    /**
     * Returns null when the token is unknown, expired, revoked, or belongs to a
     * different sales channel.
     */
    public function findAccessToken(string $accessToken, string $salesChannelId): ?OAuthAccessTokenInfo;
}
