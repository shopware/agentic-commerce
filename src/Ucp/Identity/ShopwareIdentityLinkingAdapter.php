<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Shopware\Core\PlatformRequest;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Ucp\Sdk\Adapter\IdentityLinkingAdapterInterface;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\RequestContext;

final class ShopwareIdentityLinkingAdapter implements IdentityLinkingAdapterInterface
{
    /**
     * @var list<string>
     */
    private const SUPPORTED_SCOPES = [
        'dev.ucp.shopping.cart:manage',
        'dev.ucp.shopping.order:read',
        'dev.ucp.shopping.order:manage',
    ];

    public function __construct(
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly DoctrineDbalUcpOAuthStore $oauthStore,
        private readonly OAuthClientBindingValidator $clientBindingValidator = new OAuthClientBindingValidator(),
    ) {
    }

    public function getMetadata(RequestContext $context): OAuthMetadata
    {
        $baseUri = $this->baseUri($context);

        return new OAuthMetadata(
            $baseUri,
            $baseUri.'/ucp/v1/oauth/authorize',
            $baseUri.'/ucp/v1/oauth/token',
            self::SUPPORTED_SCOPES,
            ['authorization_code', 'refresh_token'],
            ['none'],
        );
    }

    public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array
    {
        $this->clientBindingValidator->assertClientId($request->clientId, $context);
        $this->clientBindingValidator->assertRedirectUri($request->redirectUri, $request->clientId);

        if (null === $request->codeChallenge || '' === $request->codeChallenge) {
            throw new OAuthException('PKCE code challenge is required.');
        }

        if ('S256' !== $request->codeChallengeMethod) {
            throw new OAuthException('Only PKCE S256 code challenge method is supported.');
        }

        $scope = $this->normalizeScope($request->scope);
        $salesChannel = $this->contextResolver->resolveSalesChannel($context);
        $customerContext = $this->contextResolver->resolve($this->contextToken($context), $context);
        $customer = $customerContext->getCustomer();

        if (null === $customer) {
            throw new OAuthException('A logged-in Shopware customer context token is required for UCP identity linking.');
        }

        if ($customerContext->getSalesChannelId() !== $salesChannel->salesChannelId) {
            throw new OAuthException('Customer context token does not belong to the current sales channel.');
        }

        $code = $this->saveAuthorizationCode(
            $salesChannel->salesChannelId,
            $request->clientId,
            $request->redirectUri,
            $customer->getId(),
            $scope,
            $request->codeChallenge,
            $request->codeChallengeMethod,
        );

        $redirectTo = $this->appendQuery($request->redirectUri, [
            'code' => $code,
            'state' => $request->state,
            'iss' => $this->baseUri($context),
        ]);

        return [
            'client_id' => $request->clientId,
            'code' => $code,
            'state' => $request->state,
            'subject' => $customer->getId(),
            'redirect_to' => $redirectTo,
        ];
    }

    public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse
    {
        if ('refresh_token' === $request->grantType) {
            if (null === $request->refreshToken || '' === $request->refreshToken) {
                throw new OAuthException('Missing refresh token.');
            }

            $this->clientBindingValidator->assertClientId($request->clientId ?? '', $context);

            $salesChannel = $this->contextResolver->resolveSalesChannel($context);
            $tokenSet = $this->oauthStore->refreshTokenSet($request->refreshToken, $request->clientId, $salesChannel->salesChannelId);
            if (null === $tokenSet) {
                throw new OAuthException('Refresh token is invalid or expired.');
            }

            return new OAuthTokenResponse($tokenSet->accessToken, expiresIn: $tokenSet->expiresIn, refreshToken: $tokenSet->refreshToken, scope: $tokenSet->scope);
        }

        if ('authorization_code' !== $request->grantType) {
            throw new OAuthException('Only authorization_code and refresh_token grants are supported.');
        }

        if (null === $request->code || '' === $request->code) {
            throw new OAuthException('Missing authorization code.');
        }

        if (null === $request->codeVerifier || '' === $request->codeVerifier) {
            throw new OAuthException('PKCE code verifier is required.');
        }

        if (null === $request->redirectUri || '' === $request->redirectUri) {
            throw new OAuthException('Redirect URI is required for authorization code exchange.');
        }

        $this->clientBindingValidator->assertClientId($request->clientId ?? '', $context);
        $this->clientBindingValidator->assertRedirectUri($request->redirectUri, $request->clientId ?? '');

        $salesChannel = $this->contextResolver->resolveSalesChannel($context);
        $authorization = $this->oauthStore->consumeAuthorizationCode($request->code, $salesChannel->salesChannelId);
        if (null === $authorization) {
            throw new OAuthException('Authorization code is invalid, expired, or already consumed.');
        }

        if (!hash_equals($authorization->clientId, $request->clientId)) {
            throw new OAuthException('Client ID does not match the authorization code.');
        }

        if (!hash_equals($authorization->redirectUri, $request->redirectUri)) {
            throw new OAuthException('Redirect URI does not match the authorization code.');
        }

        if (!hash_equals($authorization->salesChannelId, $salesChannel->salesChannelId)) {
            throw new OAuthException('Authorization code does not belong to the current sales channel.');
        }

        if (!$this->verifyPkce($request->codeVerifier, $authorization->codeChallenge, $authorization->codeChallengeMethod)) {
            throw new OAuthException('PKCE code verifier does not match the authorization request.');
        }

        $tokenSet = $this->oauthStore->issueTokenSet(
            $salesChannel->salesChannelId,
            $authorization->clientId,
            $authorization->subject,
            $authorization->scope,
        );

        return new OAuthTokenResponse($tokenSet->accessToken, expiresIn: $tokenSet->expiresIn, refreshToken: $tokenSet->refreshToken, scope: $tokenSet->scope);
    }

    private function saveAuthorizationCode(
        string $salesChannelId,
        string $clientId,
        string $redirectUri,
        string $subject,
        string $scope,
        string $codeChallenge,
        string $codeChallengeMethod,
    ): string {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $code = 'ucp_code_'.bin2hex(random_bytes(24));

            try {
                $this->oauthStore->saveAuthorizationCode(
                    $salesChannelId,
                    $code,
                    $clientId,
                    $redirectUri,
                    $subject,
                    $scope,
                    $codeChallenge,
                    $codeChallengeMethod,
                );

                return $code;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new OAuthException('Unable to issue a unique OAuth authorization code.');
    }

    private function baseUri(RequestContext $context): string
    {
        return rtrim($context->runtimeConfiguration?->baseUri ?? ('https://'.$context->host), '/');
    }

    private function normalizeScope(string $scope): string
    {
        $requested = array_values(array_filter(explode(' ', trim($scope)), static fn (string $entry): bool => '' !== $entry));
        if ([] === $requested) {
            return implode(' ', self::SUPPORTED_SCOPES);
        }

        $unsupported = array_values(array_diff($requested, self::SUPPORTED_SCOPES));
        if ([] !== $unsupported) {
            throw new OAuthException(\sprintf('Unsupported OAuth scope "%s".', $unsupported[0]));
        }

        return implode(' ', array_values(array_unique($requested)));
    }

    private function contextToken(RequestContext $context): string
    {
        $token = $context->headers[strtolower(PlatformRequest::HEADER_CONTEXT_TOKEN)] ?? $context->headers['sw-context-token'] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw new OAuthException('Missing Shopware customer context token.');
        }

        return $token;
    }

    /**
     * @param array<string, string> $query
     */
    private function appendQuery(string $uri, array $query): string
    {
        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri.$separator.http_build_query(array_filter($query, static fn (string $value): bool => '' !== $value));
    }

    private function verifyPkce(string $verifier, string $challenge, string $method): bool
    {
        if ('S256' !== $method) {
            return false;
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $expected);
    }
}
