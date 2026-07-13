<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Gives the plugin priority over the core Agentic Commerce feature.
 *
 * When core ships the feature (detected via a representative core service) the
 * core behavior services are removed so the plugin's equivalents are the only
 * ones wired. When core is absent the pass is a no-op and the plugin provides
 * the feature standalone.
 *
 * @internal
 */
class AgenticCommerceCoexistenceCompilerPass implements CompilerPassInterface
{
    private const CORE_FEATURE_PROBE = 'Shopware\\Core\\Content\\ProductExport\\Tracking\\SalesChannelTrackingListener';

    private const CORE_BEHAVIOR_SERVICES = [
        self::CORE_FEATURE_PROBE,
        'Shopware\\Core\\Content\\ProductExport\\Subscriber\\AgenticCommerceProductExportProviderContextSubscriber',
        'Shopware\\Core\\Content\\ProductExport\\Validator\\OpenAiProductExportValidator',
        'Shopware\\Core\\Content\\ProductExport\\Validator\\GoogleProductExportValidator',
        'Shopware\\Core\\Content\\ProductExport\\Provider\\OpenAiProductExportProvider',
        'Shopware\\Core\\Content\\ProductExport\\Provider\\GoogleProductExportProvider',
    ];

    private const PRODUCT_EXPORT_DEFINITION = 'Shopware\\Core\\Content\\ProductExport\\ProductExportDefinition';

    private const PLUGIN_DATA_LAYER_SERVICES = [
        'Swag\\AgenticCommerce\\Content\\ProductExport\\AgenticProductExportHydrator',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\SalesChannelTrackingOrderDefinition',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\SalesChannelTrackingCustomerDefinition',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\Extension\\OrderSalesChannelTrackingExtension',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\Extension\\CustomerSalesChannelTrackingExtension',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\Extension\\SalesChannelProductExportTrackingExtension',
        'Swag\\AgenticCommerce\\System\\SalesChannel\\Subscriber\\AgenticCommerceSalesChannelTypeProtectionSubscriber',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Subscriber\\JsonlContentTypeSubscriber',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::CORE_FEATURE_PROBE)) {
            return;
        }

        foreach (self::CORE_BEHAVIOR_SERVICES as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }

        foreach (self::PLUGIN_DATA_LAYER_SERVICES as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }

        // Core's ProductExportDefinition already declares `provider`; revert the
        // plugin's subclass override so the field is not registered twice.
        if ($container->hasDefinition(self::PRODUCT_EXPORT_DEFINITION)) {
            $container->getDefinition(self::PRODUCT_EXPORT_DEFINITION)
                ->setClass(self::PRODUCT_EXPORT_DEFINITION);
        }
    }
}
