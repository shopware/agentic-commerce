<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class OAuthClientBindingValidator
{
    public function assertClientId(string $clientId, RequestContext $context): void
    {
        if ('' === $clientId) {
            throw new OAuthException('Missing OAuth client ID.');
        }

        $clientParts = $this->urlParts($clientId, 'UCP identity linking requires an HTTPS platform profile URI as client ID.');
        if ('https' !== $clientParts['scheme']) {
            throw new OAuthException('UCP identity linking requires an HTTPS platform profile URI as client ID.');
        }

        if (null === $context->platformProfileUri || null === $context->platformProfile || !$context->signatureVerified) {
            throw new OAuthException('UCP identity linking requires a signed platform profile request.');
        }

        if ($context->platformProfileUri !== $clientId) {
            throw new OAuthException('OAuth client ID must match the signed platform profile URI.');
        }
    }

    /**
     * Client check for the browser consent flow, where no signed platform profile
     * can be presented: the client id must still be an HTTPS platform profile URI
     * and its host must be on the merchant's platform allowlist. The client itself
     * is authenticated later, when the code is redeemed at the token endpoint.
     *
     * @param list<string> $allowedPlatformHosts
     */
    public function assertConsentClientId(string $clientId, array $allowedPlatformHosts): void
    {
        if ('' === $clientId) {
            throw new OAuthException('Missing OAuth client ID.');
        }

        $clientParts = $this->urlParts($clientId, 'UCP identity linking requires an HTTPS platform profile URI as client ID.');
        if ('https' !== $clientParts['scheme'] && !('http' === $clientParts['scheme'] && 'localhost' === $clientParts['host'])) {
            throw new OAuthException('UCP identity linking requires an HTTPS platform profile URI as client ID.');
        }

        if ([] === $allowedPlatformHosts) {
            throw new OAuthException('No agent platform is allowed to request access for this sales channel.');
        }

        if (!\in_array($clientParts['host'], array_map('strtolower', $allowedPlatformHosts), true)) {
            throw new OAuthException(\sprintf('Agent platform "%s" is not allowed for this sales channel.', $clientParts['host']));
        }
    }

    public function assertRedirectUri(string $redirectUri, string $clientId): void
    {
        if ('' === $redirectUri) {
            throw new OAuthException('Invalid OAuth redirect URI.');
        }

        $redirectParts = $this->urlParts($redirectUri, 'Invalid OAuth redirect URI.');

        if ('https' !== $redirectParts['scheme'] && !('http' === $redirectParts['scheme'] && 'localhost' === $redirectParts['host'])) {
            throw new OAuthException('OAuth redirect URI must be HTTPS, except localhost during local development.');
        }

        $clientParts = $this->urlParts($clientId, 'UCP identity linking requires an HTTPS platform profile URI as client ID.');
        if ($redirectParts === $clientParts) {
            return;
        }

        if ('http' === $redirectParts['scheme'] && 'localhost' === $redirectParts['host'] && 'localhost' === $clientParts['host']) {
            return;
        }

        throw new OAuthException('OAuth redirect URI must use the signed platform profile origin.');
    }

    /**
     * @return array{scheme: string, host: string, port: int}
     */
    private function urlParts(string $uri, string $message): array
    {
        $parts = parse_url($uri);
        if (!\is_array($parts) || !\is_string($parts['scheme'] ?? null) || !\is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            throw new OAuthException($message);
        }

        $scheme = strtolower($parts['scheme']);

        return [
            'scheme' => $scheme,
            'host' => strtolower($parts['host']),
            'port' => $parts['port'] ?? ('http' === $scheme ? 80 : 443),
        ];
    }
}
