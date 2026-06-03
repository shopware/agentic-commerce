<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Compatibility;

use Composer\InstalledVersions;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Kernel;

#[Package('framework')]
final readonly class ShopwareVersionDetector
{
    public function __construct(
        private ?string $versionOverride = null,
        private ?string $kernelVersion = null,
    ) {
    }

    public function currentVersion(): string
    {
        if (null !== $this->versionOverride) {
            return $this->versionOverride;
        }

        // Shopware exposes the real runtime version as a container parameter.
        // We prefer that over Kernel::SHOPWARE_FALLBACK_VERSION because the kernel constant is only a placeholder.
        if (\is_string($this->kernelVersion) && '' !== $this->kernelVersion) {
            return $this->kernelVersion;
        }

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('shopware/core')) {
            return (string) (InstalledVersions::getPrettyVersion('shopware/core') ?? InstalledVersions::getVersion('shopware/core') ?? '');
        }

        if (class_exists(Kernel::class) && \defined(Kernel::class.'::SHOPWARE_FALLBACK_VERSION')) {
            /** @var string $version */
            $version = Kernel::SHOPWARE_FALLBACK_VERSION;

            return $version;
        }

        return '0.0.0.0';
    }

    public function supportsAgenticDiscovery(): bool
    {
        // The Agentic Commerce product-export provider exists only on the 6.7+/trunk line.
        // 6.5 and 6.6 still install the plugin, but discovery must stay disabled there.
        return version_compare($this->normalizeVersion($this->currentVersion()), '6.7.0.0', '>=')
            && class_exists('Shopware\\Core\\Content\\ProductExport\\Provider\\AbstractAgenticCommerceProductExportProvider');
    }

    public function supportsStoreApiMcp(): bool
    {
        return version_compare($this->normalizeVersion($this->currentVersion()), '6.7.0.0', '>=')
            && class_exists('Shopware\\Core\\Framework\\Mcp\\Controller\\StoreApiMcpServerController');
    }

    private function normalizeVersion(string $version): string
    {
        $normalized = preg_replace('/[^0-9.].*$/', '', $version) ?? '0.0.0.0';
        $parts = array_pad(explode('.', $normalized), 4, '0');

        return implode('.', \array_slice($parts, 0, 4));
    }
}
