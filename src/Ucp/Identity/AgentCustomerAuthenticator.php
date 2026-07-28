<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * Resource-server side of identity linking: turns an agent's credential into the
 * customer's sales-channel context.
 *
 * With an OAuth access token this closes the loop the authorization flow opens —
 * the token's subject names the customer, its scopes bound what the agent may
 * do, and revoking the link stops it working. The context-token path stays for
 * agents that already hold a customer context (embedded checkout), but it
 * carries no scopes, so scope-restricted operations must reject it.
 *
 * @internal
 */
final class AgentCustomerAuthenticator
{
    public function __construct(
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly AccessTokenReaderInterface $accessTokenReader,
        private readonly ContextTokenGenerator $contextTokenGenerator,
    ) {
    }

    /**
     * @param string|null $requiredScope scope the access token must carry; null accepts any scope
     *
     * @throws OAuthException      the access token is unknown, expired, revoked, or lacks the scope
     * @throws ValidationException no credential was supplied, or it resolves to no customer
     */
    public function authenticate(
        AgentCustomerCredential $credential,
        RequestContext $requestContext,
        ?string $requiredScope = null,
    ): SalesChannelContext {
        $context = $credential->isAccessToken()
            ? $this->fromAccessToken($credential->accessToken ?? '', $requestContext, $requiredScope)
            : $this->fromContextToken($credential->contextToken ?? '', $requestContext);

        if (null === $context->getCustomer()) {
            throw new ValidationException('The supplied credential does not identify a customer.', ['$.headers.authorization must carry an identity-linking access token, or $.headers.sw-context-token a customer context']);
        }

        return $context;
    }

    private function fromAccessToken(string $accessToken, RequestContext $requestContext, ?string $requiredScope): SalesChannelContext
    {
        if ('' === $accessToken) {
            throw new ValidationException('Missing OAuth access token.', ['$.headers.authorization is required']);
        }

        $salesChannel = $this->contextResolver->resolveSalesChannel($requestContext);
        $token = $this->accessTokenReader->findAccessToken($accessToken, $salesChannel->salesChannelId);

        if (null === $token) {
            throw new OAuthException('Access token is invalid, expired, or revoked.');
        }

        if (null !== $requiredScope && !$token->hasScope($requiredScope)) {
            throw new OAuthException(\sprintf('Access token is missing the required scope "%s".', $requiredScope));
        }

        // A fresh context token: the agent's authority comes from the access
        // token, so it never needs to hold a customer session.
        return $this->contextResolver->resolveForCustomer(
            $token->subject,
            $this->contextTokenGenerator->generate(),
            $requestContext,
        );
    }

    private function fromContextToken(string $contextToken, RequestContext $requestContext): SalesChannelContext
    {
        if ('' === $contextToken) {
            throw new ValidationException('Missing customer credential.', ['$.headers.authorization or $.headers.sw-context-token is required']);
        }

        return $this->contextResolver->resolve($contextToken, $requestContext);
    }
}
