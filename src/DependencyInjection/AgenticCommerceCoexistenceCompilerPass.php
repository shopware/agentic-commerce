<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\DependencyInjection;

use Swag\AgenticCommerce\Compatibility\Twig\EntitySeoUrlCompatExtension;
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
 * It additionally removes plugin backport services that core supersedes on newer
 * versions (see {@see self::SUPERSEDED_BACKPORTS}), independently of the Agentic
 * Commerce feature probe.
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

    /**
     * Backport services that core supersedes on newer versions, keyed by the core service that
     * indicates the feature is available natively (removed from the container when core ships it).
     */
    private const SUPERSEDED_BACKPORTS = [
        // Core registers the `entitySeoUrl` Twig function from 6.7.14 onwards.
        'Shopware\\Core\\Framework\\Adapter\\Twig\\Extension\\EntitySeoUrlFunctionExtension' => EntitySeoUrlCompatExtension::class,
    ];

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
        // Superseded backports are versioned independently of the Agentic Commerce feature, so they
        // are handled before (and regardless of) the core feature probe below.
        foreach (self::SUPERSEDED_BACKPORTS as $coreServiceId => $backportServiceId) {
            if ($container->hasDefinition($coreServiceId) && $container->hasDefinition($backportServiceId)) {
                $container->removeDefinition($backportServiceId);
            }
        }

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
