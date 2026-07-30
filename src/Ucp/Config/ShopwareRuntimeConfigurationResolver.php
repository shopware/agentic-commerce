<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Capability\UcpExtensionAvailability;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
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
        private readonly UcpExtensionAvailability $extensionAvailability,
    ) {
    }

    public function resolve(HttpRequest $request): RuntimeConfiguration
    {
        $resolution = $this->domainResolver->resolveByAbsoluteUri($request->absoluteUri);
        $config = $this->configService->getConfig($resolution?->salesChannelId);
        $baseUri = $this->fallbackBaseUri($request->absoluteUri);
        if (null !== $resolution) {
            $baseUri = $resolution->baseUrl;
        }

        return $config->toRuntimeConfiguration(
            $baseUri,
            $resolution?->salesChannelId,
            $this->versionDetector->supportsStoreApiMcp(),
            // Keep negotiation on the same gate as profile advertisement: never
            // negotiate a capability the installation cannot serve.
            $this->extensionAvailability->filterSupportedDescriptors($config->runtimeEnabledCapabilityDescriptors()),
        );
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
