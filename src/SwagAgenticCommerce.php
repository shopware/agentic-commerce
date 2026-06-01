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
        $this->bootSdkAutoloading();

        $bundleClass = 'Ucp\\Sdk\\Symfony\\UcpSdkBundle';
        if (!class_exists($bundleClass)) {
            throw SdkNotAvailableException::bundleCouldNotBeLoaded();
        }

        return [
            new $bundleClass(),
        ];
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

    private function bootSdkAutoloading(): void
    {
        if (class_exists('Ucp\\Sdk\\Symfony\\UcpSdkBundle')) {
            return;
        }

        $pluginRoot = \dirname(__DIR__);
        $composerAutoload = $pluginRoot.'/vendor/autoload.php';

        if (is_file($composerAutoload)) {
            require_once $composerAutoload;
        }

        $sdkRoots = [
            $pluginRoot.'/../ucp-php-sdk/packages',
            $pluginRoot.'/../../ucp-php-sdk/packages',
        ];

        foreach ($sdkRoots as $sdkRoot) {
            if (!is_dir($sdkRoot.'/core/src') || !is_dir($sdkRoot.'/symfony-bundle/src')) {
                continue;
            }

            spl_autoload_register(static function (string $class) use ($sdkRoot): void {
                $prefixes = [
                    'Ucp\\Sdk\\Symfony\\' => $sdkRoot.'/symfony-bundle/src/',
                    'Ucp\\Sdk\\' => $sdkRoot.'/core/src/',
                ];

                foreach ($prefixes as $prefix => $baseDir) {
                    if (!str_starts_with($class, $prefix)) {
                        continue;
                    }

                    $relativeClass = substr($class, \strlen($prefix));
                    $path = $baseDir.str_replace('\\', '/', $relativeClass).'.php';

                    if (is_file($path)) {
                        require_once $path;
                    }

                    return;
                }
            }, true, true);

            return;
        }
    }
}
