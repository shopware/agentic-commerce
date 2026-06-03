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
