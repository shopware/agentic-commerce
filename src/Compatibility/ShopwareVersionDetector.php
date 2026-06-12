<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Compatibility;

use Composer\InstalledVersions;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Kernel;

#[Package('framework')]
final class ShopwareVersionDetector
{
    public function __construct(
        private readonly ?string $versionOverride = null,
        private readonly ?string $kernelVersion = null,
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

    public function supportsStoreApiMcp(): bool
    {
        return version_compare($this->normalizeVersion($this->currentVersion()), '6.7.0.0', '>=')
            && class_exists('Shopware\\Core\\Framework\\Mcp\\Controller\\StoreApiMcpServerController');
    }

    public function coreShipsAgenticCommerce(): bool
    {
        // Defaults::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE is defined in 6.7.10–6.7.11 only.
        // From 6.7.12+ the feature moves back to plugin-only and the constant is removed.
        return \defined('Shopware\\Core\\Defaults::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE');
    }

    public function coreShipsTrackingTables(): bool
    {
        // SalesChannelTrackingOrderDefinition is the canonical indicator that tracking
        // tables exist natively in core (added in 6.7.10, remains permanently).
        return class_exists('Shopware\\Core\\Content\\ProductExport\\Tracking\\SalesChannelTrackingOrderDefinition');
    }

    public function needsEntityDefinitionClass(): bool
    {
        // SW 6.5 and 6.6 declare EntityExtension::getDefinitionClass() as abstract.
        // SW 6.7+ replaced it with getEntityName().
        $ref = new \ReflectionClass('Shopware\\Core\\Framework\\DataAbstractionLayer\\EntityExtension');

        return $ref->hasMethod('getDefinitionClass') && $ref->getMethod('getDefinitionClass')->isAbstract();
    }

    private function normalizeVersion(string $version): string
    {
        $normalized = preg_replace('/[^0-9.].*$/', '', $version) ?? '0.0.0.0';
        $parts = array_pad(explode('.', $normalized), 4, '0');

        return implode('.', \array_slice($parts, 0, 4));
    }
}
