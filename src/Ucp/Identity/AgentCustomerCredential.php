<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

/**
 * How an agent proves it may act for a customer on a runtime request.
 *
 * `accessToken` is the OAuth credential issued by identity linking and is the
 * preferred form: the customer's consent is recorded server-side, the token is
 * scoped, and it can be revoked. `contextToken` is the pre-existing fallback
 * where the agent already holds the customer's Shopware context token.
 *
 * @internal
 */
final class AgentCustomerCredential
{
    private function __construct(
        public readonly ?string $accessToken,
        public readonly ?string $contextToken,
    ) {
    }

    public static function fromAccessToken(string $accessToken): self
    {
        return new self($accessToken, null);
    }

    public static function fromContextToken(string $contextToken): self
    {
        return new self(null, $contextToken);
    }

    public function isAccessToken(): bool
    {
        return null !== $this->accessToken;
    }
}
