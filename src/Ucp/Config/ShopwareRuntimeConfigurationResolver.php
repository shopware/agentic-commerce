<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

/** @internal */
#[Package('framework')]
final class ShopwareRuntimeConfigurationResolver implements RuntimeConfigurationResolverInterface
{
    public function __construct(
        private readonly UcpConfigService $configService,
        private readonly SalesChannelDomainResolver $domainResolver,
        private readonly ShopwareVersionDetector $versionDetector,
        private readonly RequestStack $requestStack,
        private readonly bool $profileFetchingDevelopmentMode = false,
    ) {
    }

    public function resolve(HttpRequest $request): RuntimeConfiguration
    {
        $resolution = $this->domainResolver->resolveByAbsoluteUri($this->absoluteUriWithPathPrefix($request->absoluteUri));
        $config = $this->configService->getConfig($resolution?->salesChannelId);
        $baseUri = $this->fallbackBaseUri($request->absoluteUri);
        if (null !== $resolution) {
            $baseUri = $resolution->baseUrl;
        }

        return $config->toRuntimeConfiguration($baseUri, $resolution?->salesChannelId, $this->versionDetector->supportsStoreApiMcp(), $this->profileFetchingDevelopmentMode);
    }

    /**
     * The SDK builds its request from the URI without the domain's path prefix. Only the Symfony
     * request it was built from still knows the prefix. That can be the main request while a
     * sub-request is current, as for the MCP tools behind `/ucp/mcp`.
     */
    private function absoluteUriWithPathPrefix(string $absoluteUri): string
    {
        foreach ([$this->requestStack->getCurrentRequest(), $this->requestStack->getMainRequest()] as $candidateRequest) {
            if (null !== $candidateRequest && $candidateRequest->getUri() === $absoluteUri) {
                return SalesChannelDomainResolver::absoluteRequestUri($candidateRequest);
            }
        }

        return $absoluteUri;
    }

    private function fallbackBaseUri(string $absoluteUri): string
    {
        $scheme = parse_url($absoluteUri, \PHP_URL_SCHEME);
        $host = parse_url($absoluteUri, \PHP_URL_HOST);
        $port = parse_url($absoluteUri, \PHP_URL_PORT);

        if (!\is_string($scheme) || '' === $scheme || !\is_string($host) || '' === $host) {
            return '';
        }

        $authority = $host;
        if (\is_int($port)) {
            $authority .= ':'.$port;
        }

        return $scheme.'://'.$authority;
    }
}
