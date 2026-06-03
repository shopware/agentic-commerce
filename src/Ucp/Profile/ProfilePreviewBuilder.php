<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Profile;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\ProfileBuilderInterface;

#[Package('checkout')]
final readonly class ProfilePreviewBuilder
{
    public function __construct(
        private ProfileBuilderInterface $profileBuilder,
        private ShopwareVersionDetector $versionDetector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(UcpConfig $config, string $baseUri, ?string $tenantIdentifier = null): array
    {
        return $this->profileBuilder->build(new ProfileBuildInput(
            $config->ucpVersion,
            $config->resolveBaseUri($baseUri),
            $config->runtimeTransports($this->versionDetector->supportsStoreApiMcp()),
            transportEndpoints: $config->transportEndpoints($baseUri, $this->versionDetector->supportsStoreApiMcp()),
            tenantIdentifier: $tenantIdentifier,
        ))->toArray();
    }
}
