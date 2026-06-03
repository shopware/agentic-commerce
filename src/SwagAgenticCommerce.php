<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Parameter\AdditionalBundleParameters;
use Shopware\Core\Framework\Plugin;
use Swag\AgenticCommerce\Exception\SdkNotAvailableException;
use Symfony\Component\HttpKernel\Bundle\Bundle;

#[Package('framework')]
final class SwagAgenticCommerce extends Plugin
{
    /**
     * Mirror of Shopware\Core\Defaults::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE in 6.7.10+.
     * Stable UUID shared across all versions so sales channels survive plugin/core transitions.
     */
    public const SALES_CHANNEL_TYPE_AGENTIC_COMMERCE = '5e29f9890c4d4d519a1c7f9d5c24b7c1';

    public const OPEN_AI_PRODUCT_EXPORT_CONFIG_DOMAIN = 'SwagAgenticCommerce.openAiProductExport';

    public const GOOGLE_PRODUCT_EXPORT_CONFIG_DOMAIN = 'SwagAgenticCommerce.googleProductExport';

    /** Mirror of ProductExportEntity::FILE_FORMAT_JSONL in 6.7.10+. */
    public const FILE_FORMAT_JSONL = 'jsonl';

    /**
     * @return list<Bundle>
     */
    public function getAdditionalBundles(AdditionalBundleParameters $parameters): array
    {
        $bundleClass = 'Ucp\\Sdk\\Symfony\\UcpSdkBundle';
        if (!class_exists($bundleClass)) {
            throw SdkNotAvailableException::bundleCouldNotBeLoaded();
        }

        return [
            new $bundleClass(),
        ];
    }

    public function executeComposerCommands(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function enrichPrivileges(): array
    {
        return [
            'ucp.viewer' => ['system_config:read', 'sales_channel:read', 'sales_channel_domain:read'],
            'ucp.editor' => ['ucp.viewer', 'system_config:update'],
            'ucp.key_rotator' => ['ucp.viewer'],
        ];
    }
}
