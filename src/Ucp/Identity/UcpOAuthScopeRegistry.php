<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Exception\OAuthException;

/** @internal */
#[Package('framework')]
final class UcpOAuthScopeRegistry
{
    /**
     * The scopes this plugin's own capabilities answer for.
     *
     * @var list<string>
     */
    private const CORE_SCOPES = [
        'dev.ucp.shopping.cart:manage',
        'dev.ucp.shopping.order:read',
        'dev.ucp.shopping.order:manage',
    ];

    /**
     * @var list<string>|null
     */
    private ?array $supported = null;

    /**
     * @param iterable<AbstractUcpOAuthScopeProvider> $scopeProviders
     */
    public function __construct(private readonly iterable $scopeProviders = [])
    {
    }

    /**
     * Every grantable scope: the core ones plus whatever extensions registered.
     *
     * @return list<string>
     */
    public function supported(): array
    {
        if (null === $this->supported) {
            $scopes = self::CORE_SCOPES;

            foreach ($this->scopeProviders as $provider) {
                $scopes = array_merge($scopes, $provider->getScopes());
            }

            $this->supported = array_values(array_unique($scopes));
        }

        return $this->supported;
    }

    /**
     * Expands an empty request to every supported scope and rejects anything else.
     */
    public function normalize(string $scope): string
    {
        $supported = $this->supported();

        $requested = array_values(array_filter(explode(' ', trim($scope)), static fn (string $entry): bool => '' !== $entry));
        if ([] === $requested) {
            return implode(' ', $supported);
        }

        $unsupported = array_values(array_diff($requested, $supported));
        if ([] !== $unsupported) {
            throw new OAuthException(\sprintf('Unsupported OAuth scope "%s". Supported scopes: %s.', $unsupported[0], implode(' ', $supported)));
        }

        return implode(' ', array_values(array_unique($requested)));
    }
}
