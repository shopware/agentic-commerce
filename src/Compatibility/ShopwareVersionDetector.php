<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Compatibility;

use Composer\InstalledVersions;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Kernel;

/** @internal */
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
        if (!version_compare($this->normalizeVersion($this->currentVersion()), '6.7.0.0', '>=')) {
            return false;
        }

        if (!class_exists('Shopware\\Core\\Framework\\Mcp\\Controller\\StoreApiMcpServerController')) {
            return false;
        }

        // The controller ships behind the MCP_SERVER feature flag (experimental until v6.8.0).
        // The class exists but every request to /store-api/_mcp returns 404 when the flag is
        // inactive, so treat flag activation as part of availability.
        // If the flag has been removed (graduated to always-on), assume available.
        return !Feature::has('MCP_SERVER') || Feature::isActive('MCP_SERVER');
    }

    public function needsRobotsTrackingAllowPatch(): bool
    {
        $version = $this->normalizeVersion($this->currentVersion());

        // The storefront robots.txt arrived in 6.7.1.0 and core emits this Allow itself from
        // 6.7.13.0, so the plugin only needs to add it for the versions in between.
        return version_compare($version, '6.7.1.0', '>=')
            && version_compare($version, '6.7.13.0', '<');
    }

    public function needsSystemConfigXsdCompatPatch(): bool
    {
        $version = $this->normalizeVersion($this->currentVersion());

        // Shopware 6.5's core config.xsd uses a non-deterministic content model that
        // libxml2 >= 2.13 rejects, breaking ALL system-config validation. The plugin
        // ships a permissive copy and swaps it in on 6.5 only. From 6.6 core's schema
        // is fixed and stays current (e.g. it adds <subtitle> in 6.7), so the bundled
        // copy must NOT be used there or it wrongly rejects valid newer core config
        // such as basicInformation.xml. Scoping to the 6.5 range also means an
        // unknown/unresolvable version fails the check, so the frozen copy is never
        // imposed speculatively.
        return version_compare($version, '6.5.0.0', '>=')
            && version_compare($version, '6.6.0.0', '<');
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
