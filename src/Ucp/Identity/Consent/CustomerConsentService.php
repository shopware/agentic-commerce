<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity\Consent;

use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Identity\AuthorizationCodeIssuerInterface;
use Swag\AgenticCommerce\Ucp\Identity\OAuthClientBindingValidator;
use Swag\AgenticCommerce\Ucp\Identity\ShopwareIdentityLinkingAdapter;
use Ucp\Sdk\Exception\OAuthException;

/**
 * The browser half of identity linking: the customer sees who is asking for
 * what, and only their approval mints an authorization code.
 *
 * Why the client is checked differently here than in the API flow: a browser
 * cannot present a signed UCP-Agent profile, so this endpoint authenticates the
 * *customer*, not the client — as OAuth intends. The client is still pinned
 * three ways: its host must be on the merchant's platform allowlist, the
 * redirect URI must share the client id's origin, and the code is bound to the
 * client id so redeeming it at the token endpoint still requires the signed
 * platform profile.
 *
 * @internal
 */
final class CustomerConsentService
{
    public const CONSENT_PATH = '/account/ucp/authorize';

    /** Human-readable purpose per scope, shown on the consent page. */
    private const SCOPE_DESCRIPTIONS = [
        'dev.ucp.shopping.cart:manage' => 'Build and change shopping carts for you',
        'dev.ucp.shopping.order:read' => 'See your orders',
        'dev.ucp.shopping.order:manage' => 'Place orders on your behalf',
        ShopwareIdentityLinkingAdapter::SCOPE_QUOTE_MANAGE => 'Request, negotiate and accept quotes on your behalf',
    ];

    public function __construct(
        private readonly AuthorizationCodeIssuerInterface $codeIssuer,
        private readonly UcpConfigService $configService,
        private readonly OAuthClientBindingValidator $clientBindingValidator = new OAuthClientBindingValidator(),
    ) {
    }

    /**
     * @param array<string, mixed> $parameters query parameters of the authorization request
     *
     * @throws OAuthException
     */
    public function parse(array $parameters, string $salesChannelId): CustomerConsentRequest
    {
        $responseType = $this->stringParameter($parameters, 'response_type');
        if ('' !== $responseType && 'code' !== $responseType) {
            throw new OAuthException('Only the authorization_code response type is supported.');
        }

        $clientId = $this->stringParameter($parameters, 'client_id');
        $redirectUri = $this->stringParameter($parameters, 'redirect_uri');
        $codeChallenge = $this->stringParameter($parameters, 'code_challenge');
        $codeChallengeMethod = $this->stringParameter($parameters, 'code_challenge_method');

        $this->clientBindingValidator->assertConsentClientId($clientId, $this->allowedPlatformHosts($salesChannelId));
        $this->clientBindingValidator->assertRedirectUri($redirectUri, $clientId);

        if ('' === $codeChallenge) {
            throw new OAuthException('PKCE code challenge is required.');
        }

        if ('S256' !== $codeChallengeMethod) {
            throw new OAuthException('Only PKCE S256 code challenge method is supported.');
        }

        return new CustomerConsentRequest(
            $clientId,
            $redirectUri,
            $this->scopes($this->stringParameter($parameters, 'scope')),
            $this->stringParameter($parameters, 'state'),
            $codeChallenge,
            $codeChallengeMethod,
        );
    }

    /**
     * @return list<array{scope: string, description: string}>
     */
    public function describeScopes(CustomerConsentRequest $request): array
    {
        $described = [];

        foreach ($request->scopes as $scope) {
            $described[] = [
                'scope' => $scope,
                'description' => self::SCOPE_DESCRIPTIONS[$scope] ?? $scope,
            ];
        }

        return $described;
    }

    /**
     * Records the customer's approval and returns the URL to send them back to.
     */
    public function approve(CustomerConsentRequest $request, string $customerId, string $salesChannelId, string $issuer): string
    {
        $code = $this->codeIssuer->issueAuthorizationCode(
            $salesChannelId,
            $request->clientId,
            $request->redirectUri,
            $customerId,
            implode(' ', $request->scopes),
            $request->codeChallenge,
            $request->codeChallengeMethod,
        );

        return $this->appendQuery($request->redirectUri, [
            'code' => $code,
            'state' => $request->state,
            'iss' => rtrim($issuer, '/'),
        ]);
    }

    /**
     * A refusal is reported to the client the way RFC 6749 §4.1.2.1 requires, so
     * the agent learns the outcome instead of hanging.
     */
    public function deny(CustomerConsentRequest $request): string
    {
        return $this->appendQuery($request->redirectUri, [
            'error' => 'access_denied',
            'error_description' => 'The customer declined the authorization request.',
            'state' => $request->state,
        ]);
    }

    /**
     * @return list<string>
     */
    private function scopes(string $scope): array
    {
        $requested = array_values(array_filter(explode(' ', trim($scope)), static fn (string $entry): bool => '' !== $entry));

        if ([] === $requested) {
            return ShopwareIdentityLinkingAdapter::SUPPORTED_SCOPES;
        }

        $unsupported = array_values(array_diff($requested, ShopwareIdentityLinkingAdapter::SUPPORTED_SCOPES));
        if ([] !== $unsupported) {
            throw new OAuthException(\sprintf('Unsupported OAuth scope "%s".', $unsupported[0]));
        }

        return array_values(array_unique($requested));
    }

    /**
     * @return list<string>
     */
    private function allowedPlatformHosts(string $salesChannelId): array
    {
        return $this->configService->getConfig($salesChannelId)->platformAllowlist;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function stringParameter(array $parameters, string $name): string
    {
        $value = $parameters[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, string> $query
     */
    private function appendQuery(string $uri, array $query): string
    {
        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri.$separator.http_build_query(array_filter($query, static fn (string $value): bool => '' !== $value));
    }
}
