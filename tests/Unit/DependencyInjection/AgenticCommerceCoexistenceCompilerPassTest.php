<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Compatibility\Twig\EntitySeoUrlCompatExtension;
use Swag\AgenticCommerce\DependencyInjection\AgenticCommerceCoexistenceCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * @internal
 */
#[CoversClass(AgenticCommerceCoexistenceCompilerPass::class)]
class AgenticCommerceCoexistenceCompilerPassTest extends TestCase
{
    private const CORE_PROBE = 'Shopware\\Core\\Content\\ProductExport\\Tracking\\SalesChannelTrackingListener';
    private const PRODUCT_EXPORT_DEFINITION = 'Shopware\\Core\\Content\\ProductExport\\ProductExportDefinition';

    private const CORE_ENTITY_SEO_URL = 'Shopware\\Core\\Framework\\Adapter\\Twig\\Extension\\EntitySeoUrlFunctionExtension';

    /** @var list<string> */
    private const CORE_BEHAVIOR = [
        self::CORE_PROBE,
        'Shopware\\Core\\Content\\ProductExport\\Subscriber\\AgenticCommerceProductExportProviderContextSubscriber',
        'Shopware\\Core\\Content\\ProductExport\\Validator\\OpenAiProductExportValidator',
        'Shopware\\Core\\Content\\ProductExport\\Validator\\GoogleProductExportValidator',
        'Shopware\\Core\\Content\\ProductExport\\Provider\\OpenAiProductExportProvider',
        'Shopware\\Core\\Content\\ProductExport\\Provider\\GoogleProductExportProvider',
    ];

    /** @var list<string> */
    private const PLUGIN_DATA_LAYER = [
        'Swag\\AgenticCommerce\\Content\\ProductExport\\AgenticProductExportHydrator',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\SalesChannelTrackingOrderDefinition',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\SalesChannelTrackingCustomerDefinition',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\Extension\\OrderSalesChannelTrackingExtension',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\Extension\\CustomerSalesChannelTrackingExtension',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\Extension\\SalesChannelProductExportTrackingExtension',
        'Swag\\AgenticCommerce\\System\\SalesChannel\\Subscriber\\AgenticCommerceSalesChannelTypeProtectionSubscriber',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Subscriber\\JsonlContentTypeSubscriber',
    ];

    /** @var list<string> */
    private const PLUGIN_BEHAVIOR = [
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Tracking\\SalesChannelTrackingListener',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Validator\\OpenAiProductExportValidator',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Validator\\GoogleProductExportValidator',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Provider\\OpenAiProductExportProvider',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Provider\\GoogleProductExportProvider',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Subscriber\\AgenticCommerceProductExportProviderContextSubscriber',
        'Swag\\AgenticCommerce\\Content\\ProductExport\\Provider\\AgenticCommerceProductExportProviderRegistry',
    ];

    public function testPassIsNoOpWhenCoreFeatureIsAbsent(): void
    {
        $container = new ContainerBuilder();

        foreach (self::PLUGIN_DATA_LAYER as $id) {
            $container->setDefinition($id, new Definition(\stdClass::class));
        }
        foreach (self::PLUGIN_BEHAVIOR as $id) {
            $container->setDefinition($id, new Definition(\stdClass::class));
        }

        (new AgenticCommerceCoexistenceCompilerPass())->process($container);

        foreach (self::PLUGIN_DATA_LAYER as $id) {
            static::assertTrue($container->hasDefinition($id), "Expected {$id} to still be registered");
        }
        foreach (self::PLUGIN_BEHAVIOR as $id) {
            static::assertTrue($container->hasDefinition($id), "Expected {$id} to still be registered");
        }
    }

    public function testPassRemovesCoreServicesAndPluginDataLayerWhenCoreIsPresent(): void
    {
        $container = new ContainerBuilder();

        foreach (self::CORE_BEHAVIOR as $id) {
            $container->setDefinition($id, new Definition(\stdClass::class));
        }

        $exportDefinition = new Definition(\stdClass::class);
        $exportDefinition->setClass('Swag\\AgenticCommerce\\Content\\ProductExport\\AgenticProductExportDefinition');
        $container->setDefinition(self::PRODUCT_EXPORT_DEFINITION, $exportDefinition);

        foreach (self::PLUGIN_DATA_LAYER as $id) {
            $container->setDefinition($id, new Definition(\stdClass::class));
        }
        foreach (self::PLUGIN_BEHAVIOR as $id) {
            $container->setDefinition($id, new Definition(\stdClass::class));
        }

        (new AgenticCommerceCoexistenceCompilerPass())->process($container);

        foreach (self::CORE_BEHAVIOR as $id) {
            static::assertFalse($container->hasDefinition($id), "Expected core service {$id} to be removed");
        }

        foreach (self::PLUGIN_DATA_LAYER as $id) {
            static::assertFalse($container->hasDefinition($id), "Expected plugin data-layer service {$id} to be removed");
        }

        static::assertSame(
            self::PRODUCT_EXPORT_DEFINITION,
            $container->getDefinition(self::PRODUCT_EXPORT_DEFINITION)->getClass(),
            'ProductExportDefinition must be reverted to core\'s own class',
        );

        foreach (self::PLUGIN_BEHAVIOR as $id) {
            static::assertTrue($container->hasDefinition($id), "Expected plugin behavior service {$id} to remain");
        }
    }

    public function testRemovesEntitySeoUrlBackportWhenCoreShipsIt(): void
    {
        $container = new ContainerBuilder();
        // Deliberately without the core feature probe: superseded backports are handled independently.
        $container->setDefinition(self::CORE_ENTITY_SEO_URL, new Definition(\stdClass::class));
        $container->setDefinition(EntitySeoUrlCompatExtension::class, new Definition(\stdClass::class));

        (new AgenticCommerceCoexistenceCompilerPass())->process($container);

        static::assertFalse(
            $container->hasDefinition(EntitySeoUrlCompatExtension::class),
            'Expected the entitySeoUrl backport to be removed when core ships the function',
        );
    }

    public function testKeepsEntitySeoUrlBackportWhenCoreDoesNotShipIt(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(EntitySeoUrlCompatExtension::class, new Definition(\stdClass::class));

        (new AgenticCommerceCoexistenceCompilerPass())->process($container);

        static::assertTrue(
            $container->hasDefinition(EntitySeoUrlCompatExtension::class),
            'Expected the entitySeoUrl backport to remain when core does not ship the function',
        );
    }

    public function testPassIsIdempotentWhenSomeServicesAreAlreadyMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(self::CORE_PROBE, new Definition(\stdClass::class));

        // Only the probe is present; all other services are absent — must not throw.
        (new AgenticCommerceCoexistenceCompilerPass())->process($container);

        static::assertFalse($container->hasDefinition(self::CORE_PROBE));
    }
}
