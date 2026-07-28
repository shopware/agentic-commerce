<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity\Consent;

/**
 * A validated authorization request as it arrives in the customer's browser.
 *
 * @internal
 */
final class CustomerConsentRequest
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly array $scopes,
        public readonly string $state,
        public readonly string $codeChallenge,
        public readonly string $codeChallengeMethod,
    ) {
    }

    /**
     * The host the customer is about to grant access to. Shown on the consent
     * page: it is the part of the client id a person can actually recognise.
     */
    public function clientHost(): string
    {
        $host = parse_url($this->clientId, \PHP_URL_HOST);

        return \is_string($host) ? $host : $this->clientId;
    }

    /**
     * @return array<string, string>
     */
    public function toQueryParameters(): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $this->scopes),
            'state' => $this->state,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => $this->codeChallengeMethod,
        ];
    }
}
