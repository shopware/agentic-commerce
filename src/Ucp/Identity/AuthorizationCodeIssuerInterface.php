<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

/**
 * Write side of the OAuth code store used by the consent flow.
 *
 * The consent page only needs to mint a code once the customer approves, so it
 * depends on this narrow port rather than the whole store.
 *
 * @internal
 */
interface AuthorizationCodeIssuerInterface
{
    public function issueAuthorizationCode(
        string $salesChannelId,
        string $clientId,
        string $redirectUri,
        string $subject,
        string $scope,
        string $codeChallenge,
        string $codeChallengeMethod,
    ): string;
}
