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
    private const BUNDLED_SDK_MARKER = __DIR__.'/../.swag-agentic-commerce-bundled-sdk';

    /**
     * @return list<Bundle>
     */
    public function getAdditionalBundles(AdditionalBundleParameters $parameters): array
    {
        $this->loadBundledSdkAutoload();

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
        return !is_file(self::BUNDLED_SDK_MARKER);
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

    private function loadBundledSdkAutoload(): void
    {
        if (!is_file(self::BUNDLED_SDK_MARKER)) {
            return;
        }

        $autoloadPath = __DIR__.'/../vendor/autoload.php';
        if (is_file($autoloadPath)) {
            require_once $autoloadPath;
        }
    }
}
