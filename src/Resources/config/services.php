<?php

declare(strict_types=1);

use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartDeleteRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemAddRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemRemoveRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemUpdateRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartLoadRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartDeleteRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartItemAddRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartItemRemoveRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartItemUpdateRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartLoadRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\RegisterRoute;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\OrderRoute;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductListRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\ProductListRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRoute;
use Shopware\Core\Content\ProductExport\ProductExportDefinition;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\Country\SalesChannel\CountryRoute;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SystemConfig\Util\ConfigReader;
use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileBridge;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileSyncSubscriber;
use Swag\AgenticCommerce\AgenticFiles\Fallback\FallbackAgenticFileController;
use Swag\AgenticCommerce\AgenticFiles\Fallback\FallbackAgenticFileRenderer;
use Swag\AgenticCommerce\AgenticFiles\Fallback\RemoveLeadingSpacesTwigExtension;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Content\ProductExport\AgenticProductExportDefinition;
use Swag\AgenticCommerce\Content\ProductExport\AgenticProductExportHydrator;
use Swag\AgenticCommerce\Content\ProductExport\Provider\AgenticCommerceProductExportProviderRegistry;
use Swag\AgenticCommerce\Content\ProductExport\Provider\GoogleProductExportProvider;
use Swag\AgenticCommerce\Content\ProductExport\Provider\OpenAiProductExportProvider;
use Swag\AgenticCommerce\Content\ProductExport\Service\JsonlAwareProductExportRenderer;
use Swag\AgenticCommerce\Content\ProductExport\Subscriber\AgenticCommerceProductExportProviderContextSubscriber;
use Swag\AgenticCommerce\Content\ProductExport\Subscriber\JsonlContentTypeSubscriber;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\Extension\CustomerSalesChannelTrackingExtension;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\Extension\OrderSalesChannelTrackingExtension;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\Extension\SalesChannelProductExportTrackingExtension;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingCustomerDefinition;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingListener;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingOrderDefinition;
use Swag\AgenticCommerce\Content\ProductExport\Validator\GoogleProductExportValidator;
use Swag\AgenticCommerce\Content\ProductExport\Validator\JsonlRowParser;
use Swag\AgenticCommerce\Content\ProductExport\Validator\OpenAiProductExportValidator;
use Swag\AgenticCommerce\System\SalesChannel\Subscriber\AgenticCommerceSalesChannelTypeProtectionSubscriber;
use Swag\AgenticCommerce\System\SystemConfig\CompatConfigReader;
use Swag\AgenticCommerce\Ucp\Adapter\ShopwareCartAdapter;
use Swag\AgenticCommerce\Ucp\Adapter\ShopwareCatalogAdapter;
use Swag\AgenticCommerce\Ucp\Adapter\ShopwareCheckoutAdapter;
use Swag\AgenticCommerce\Ucp\Adapter\ShopwareDiscountAdapter;
use Swag\AgenticCommerce\Ucp\Adapter\ShopwareOrderAdapter;
use Swag\AgenticCommerce\Ucp\Admin\Api\UcpAdminController;
use Swag\AgenticCommerce\Ucp\Capability\CartCapability;
use Swag\AgenticCommerce\Ucp\Capability\CatalogCapability;
use Swag\AgenticCommerce\Ucp\Capability\CheckoutCapability;
use Swag\AgenticCommerce\Ucp\Capability\DiscountCapability;
use Swag\AgenticCommerce\Ucp\Capability\IdentityLinkingCapability;
use Swag\AgenticCommerce\Ucp\Capability\OrderCapability;
use Swag\AgenticCommerce\Ucp\Capability\PaymentTokenizationCapability;
use Swag\AgenticCommerce\Ucp\Capability\UcpExtensionAvailability;
use Swag\AgenticCommerce\Ucp\Command\SeedSmokeCatalogCommand;
use Swag\AgenticCommerce\Ucp\Config\DoctrineDbalUcpConfigRepository;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\ShopwareRuntimeConfigurationResolver;
use Swag\AgenticCommerce\Ucp\Config\SystemConfigLegacyConfigStore;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Embedded\EmbeddedResponseListener;
use Swag\AgenticCommerce\Ucp\Identity\ShopwareIdentityLinkingAdapter;
use Swag\AgenticCommerce\Ucp\Mcp\Api\UcpMcpProxyController;
use Swag\AgenticCommerce\Ucp\Mcp\Routing\StoreApiMcpRouteScopeWhitelist;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCartCancelTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCartCreateTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCartGetTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCartUpdateTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCatalogLookupTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCatalogSearchTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCancelTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCompleteTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCreateTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutGetTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutUpdateTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpDiscountApplyTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpOrderGetTool;
use Swag\AgenticCommerce\Ucp\Payment\ShopwareInvoicePaymentHandler;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Swag\AgenticCommerce\Ucp\Test\Api\TestWebhookController;
use Swag\AgenticCommerce\Ucp\Test\WebhookCaptureStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

use Ucp\Sdk\Adapter\CartAdapterInterface;
use Ucp\Sdk\Adapter\CatalogAdapterInterface;
use Ucp\Sdk\Adapter\CheckoutAdapterInterface;
use Ucp\Sdk\Adapter\DiscountAdapterInterface;
use Ucp\Sdk\Adapter\OrderAdapterInterface;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

return static function (ContainerConfigurator $container): void {
    $container->extension('ucp_sdk', [
        'version' => '2026-04-08',
        'signature_policy' => 'strict',
        'idempotency_required' => true,
        'signing_keys' => [
            'auto_generate' => true,
            'default_kid' => 'default',
            'algorithm' => 'ES256',
            'retire_after' => 'P30D',
            'retired_key_retention' => 'P30D',
        ],
        'storage' => [
            'dsn' => env('DATABASE_URL'),
        ],
    ]);

    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    $services->load('Swag\\AgenticCommerce\\', __DIR__.'/../../*')
        ->exclude([__DIR__.'/../../Resources']);

    // DAL repositories are bound by service id, not type — named args required.

    $services->set(SalesChannelViewProvider::class)
        ->arg('$salesChannelRepository', service('sales_channel.repository'));

    $services->set(SalesChannelDomainResolver::class)
        ->arg('$domainRepository', service('sales_channel_domain.repository'));

    $services->set(FallbackAgenticFileRenderer::class)
        ->arg('$salesChannelRepository', service('sales_channel.repository'));

    if (!CoreSalesChannelFileFeature::isAvailableByClass()) {
        $services->set(RemoveLeadingSpacesTwigExtension::class);
    }

    $services->set(SeedSmokeCatalogCommand::class)
        ->arg('$productRepository', service('product.repository'))
        ->arg('$taxRepository', service('tax.repository'))
        ->arg('$appEnv', param('kernel.environment'))
        ->arg('$smokeCatalogSeedEnabled', env('bool:default:defaults_bool_false:SWAG_AGENTIC_COMMERCE_SMOKE_SEED'));

    $services->set(ShopwareVersionDetector::class)
        ->arg('$kernelVersion', param('kernel.shopware_version'));

    // Shopware decorable-route aliases.

    $services->alias(SalesChannelContextServiceInterface::class, SalesChannelContextService::class);
    $services->alias(AbstractCartLoadRoute::class, CartLoadRoute::class);
    $services->alias(AbstractCartItemAddRoute::class, CartItemAddRoute::class);
    $services->alias(AbstractCartItemUpdateRoute::class, CartItemUpdateRoute::class);
    $services->alias(AbstractCartItemRemoveRoute::class, CartItemRemoveRoute::class);
    $services->alias(AbstractCartDeleteRoute::class, CartDeleteRoute::class);
    $services->alias(AbstractCartOrderRoute::class, CartOrderRoute::class);
    $services->alias(AbstractRegisterRoute::class, RegisterRoute::class);
    $services->alias(AbstractOrderRoute::class, OrderRoute::class);
    $services->alias(AbstractCountryRoute::class, CountryRoute::class);
    $services->alias(AbstractProductListRoute::class, ProductListRoute::class);
    $services->alias(AbstractProductSearchRoute::class, ProductSearchRoute::class);
    $services->alias(AbstractProductDetailRoute::class, ProductDetailRoute::class);

    // SDK adapter and capability bindings.

    $services->alias(CatalogAdapterInterface::class, ShopwareCatalogAdapter::class);
    $services->alias(CartAdapterInterface::class, ShopwareCartAdapter::class);
    $services->alias(CheckoutAdapterInterface::class, ShopwareCheckoutAdapter::class);
    $services->alias(DiscountAdapterInterface::class, ShopwareDiscountAdapter::class);
    $services->alias(OrderAdapterInterface::class, ShopwareOrderAdapter::class);

    $services->alias(CatalogCapabilityInterface::class, CatalogCapability::class);
    $services->alias(CartCapabilityInterface::class, CartCapability::class);
    $services->alias(CheckoutCapabilityInterface::class, CheckoutCapability::class);
    $services->alias(DiscountCapabilityInterface::class, DiscountCapability::class);
    $services->alias(OrderCapabilityInterface::class, OrderCapability::class);
    $services->alias(IdentityLinkingCapabilityInterface::class, IdentityLinkingCapability::class);
    $services->alias(TokenizationCapabilityInterface::class, PaymentTokenizationCapability::class);

    // Capabilities that collect tagged adapters via iteration.

    $services->set(IdentityLinkingCapability::class)
        ->arg('$adapterIterable', tagged_iterator('ucp_sdk.adapter.identity_linking'));

    $services->set(UcpExtensionAvailability::class)
        ->arg('$identityLinkingAdapterIterable', tagged_iterator('ucp_sdk.adapter.identity_linking'));

    // Tagged service registrations.

    $services->set(ShopwareIdentityLinkingAdapter::class)
        ->tag('ucp_sdk.adapter.identity_linking');

    $services->set(ShopwareInvoicePaymentHandler::class)
        ->tag('ucp_sdk.payment_handler');

    // Store API MCP tools.

    $services->set(UcpCatalogSearchTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpCatalogLookupTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpCartCreateTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpCartGetTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpCartUpdateTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpCartCancelTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpDiscountApplyTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpCheckoutCreateTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpCheckoutGetTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpCheckoutUpdateTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpCheckoutCompleteTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpCheckoutCancelTool::class)->tag('shopware.store_api_mcp.tool');
    $services->set(UcpOrderGetTool::class)->tag('shopware.store_api_mcp.tool');

    $services->set(StoreApiMcpRouteScopeWhitelist::class)
        ->tag('shopware.route_scope_whitelist');

    // Public controllers.

    $services->set(UcpMcpProxyController::class)
        ->arg('$salesChannelRepository', service('sales_channel.repository'))
        ->tag('controller.service_arguments');

    $services->set(UcpAdminController::class)
        ->tag('controller.service_arguments');

    $services->set(WebhookCaptureStore::class)
        ->arg('$projectDir', param('kernel.project_dir'));

    $services->set(TestWebhookController::class)
        ->arg('$appEnv', param('kernel.environment'))
        ->arg('$testCaptureEnabled', env('bool:default:defaults_bool_false:SWAG_AGENTIC_COMMERCE_TEST_CAPTURE'))
        ->tag('controller.service_arguments');

    $services->set(FallbackAgenticFileController::class)
        ->tag('controller.service_arguments');

    // Config layer.

    $services->alias(UcpConfigRepositoryInterface::class, DoctrineDbalUcpConfigRepository::class);
    $services->alias(LegacyConfigStoreInterface::class, SystemConfigLegacyConfigStore::class);
    $services->alias(RuntimeConfigurationResolverInterface::class, ShopwareRuntimeConfigurationResolver::class);
    $services->alias(AgenticFilesCoreBridgeInterface::class, CoreSalesChannelFileBridge::class);

    // Event listeners.

    $services->set(EmbeddedResponseListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 10000])
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse', 'priority' => -1024]);

    $services->set(CoreSalesChannelFileSyncSubscriber::class);

    // ── Product export: entity definition override ────────────────────────────

    // Replace core ProductExportDefinition with our subclass that adds the `provider` field.
    $services->set(ProductExportDefinition::class, AgenticProductExportDefinition::class)
        ->tag('shopware.entity.definition');

    $services->set(AgenticProductExportHydrator::class)
        ->public()
        ->arg('$container', service('service_container'));

    // ── Product export: providers ─────────────────────────────────────────────

    $services->set(AgenticCommerceProductExportProviderRegistry::class)
        ->arg('$providers', tagged_iterator('swag_agentic_commerce.product_export.provider'));

    $services->set(OpenAiProductExportProvider::class)
        ->arg('$salesChannelRepository', service('sales_channel.repository'))
        ->tag('swag_agentic_commerce.product_export.provider');

    $services->set(GoogleProductExportProvider::class)
        ->arg('$salesChannelRepository', service('sales_channel.repository'))
        ->tag('swag_agentic_commerce.product_export.provider');

    // ── Product export: renderer decorator ───────────────────────────────────

    $services->set(JsonlAwareProductExportRenderer::class)
        ->decorate('Shopware\\Core\\Content\\ProductExport\\Service\\ProductExportRenderer')
        ->arg('$inner', service('.inner'));

    // ── Product export: subscribers ───────────────────────────────────────────

    $services->set(AgenticCommerceProductExportProviderContextSubscriber::class);

    $services->set(JsonlContentTypeSubscriber::class);

    // ── Product export: validators ────────────────────────────────────────────

    $services->set(JsonlRowParser::class);

    $services->set(OpenAiProductExportValidator::class)
        ->tag('shopware.product_export.validator');

    $services->set(GoogleProductExportValidator::class)
        ->tag('shopware.product_export.validator');

    // ── Tracking: entity definitions (plugin-owned until SW 6.7.10+) ─────────

    $services->set(SalesChannelTrackingOrderDefinition::class)
        ->tag('shopware.entity.definition', ['entity' => 'sales_channel_tracking_order']);

    $services->set(SalesChannelTrackingCustomerDefinition::class)
        ->tag('shopware.entity.definition', ['entity' => 'sales_channel_tracking_customer']);

    // ── Tracking: entity extensions ───────────────────────────────────────────

    $services->set(OrderSalesChannelTrackingExtension::class)
        ->tag('shopware.entity.extension');

    $services->set(CustomerSalesChannelTrackingExtension::class)
        ->tag('shopware.entity.extension');

    $services->set(SalesChannelProductExportTrackingExtension::class)
        ->tag('shopware.entity.extension');

    // ── Tracking: listener (guards internally via coreShipsTrackingTables()) ──

    $services->set(SalesChannelTrackingListener::class)
        ->arg('$salesChannelRepository', service('sales_channel.repository'))
        ->arg('$salesChannelTrackingOrderRepository', service('sales_channel_tracking_order.repository'))
        ->arg('$salesChannelTrackingCustomerRepository', service('sales_channel_tracking_customer.repository'))
        ->arg('$cache', service('cache.object'));

    // ── Sales channel type protection subscriber ──────────────────────────────

    $services->set(AgenticCommerceSalesChannelTypeProtectionSubscriber::class)
        ->tag('kernel.event_subscriber');

    // ── CompatConfigReader: fixes libxml2 2.13+ rejection of 6.5 XSD ─────────

    $services->set(ConfigReader::class, CompatConfigReader::class)
        ->public();
};
