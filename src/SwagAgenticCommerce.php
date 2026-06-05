<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Parameter\AdditionalBundleParameters;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Kernel;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileBridge;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Swag\AgenticCommerce\AgenticFiles\Fallback\AgenticFilesFallbackBundle;
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

        /** @var list<Bundle> $bundles */
        $bundles = [new $bundleClass()];

        if (!CoreSalesChannelFileFeature::isAvailableByClass()) {
            $bundles[] = new AgenticFilesFallbackBundle();
        }

        return $bundles;
    }

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $this->syncCoreAgenticFiles();
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->syncCoreAgenticFiles();
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        $this->syncCoreAgenticFiles();
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

    private function syncCoreAgenticFiles(): void
    {
        CoreSalesChannelFileBridge::syncActiveUcpSalesChannelsWithConnection(Kernel::getConnection());
    }
}
