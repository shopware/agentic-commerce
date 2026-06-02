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
use Shopware\Core\Content\Product\SalesChannel\Detail\AbstractProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Detail\ProductDetailRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\AbstractProductSearchRoute;
use Shopware\Core\Content\Product\SalesChannel\Search\ProductSearchRoute;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Swag\AgenticCommerce\AgenticDiscovery\DiscoveryBridgeInterface;
use Swag\AgenticCommerce\AgenticDiscovery\TrunkDiscoveryBridge;
use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileBridge;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileSyncSubscriber;
use Swag\AgenticCommerce\AgenticFiles\Fallback\FallbackAgenticFileController;
use Swag\AgenticCommerce\AgenticFiles\Fallback\FallbackAgenticFileRenderer;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
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
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerAddressResolver;
use Swag\AgenticCommerce\Ucp\Customer\GuestCustomerContextProvisioner;
use Swag\AgenticCommerce\Ucp\Embedded\EmbeddedResponseListener;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareOrderGateway;
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

    $services->set(GuestCustomerContextProvisioner::class)
        ->arg('$customerRepository', service('customer.repository'))
        ->arg('$salutationRepository', service('salutation.repository'));

    $services->set(GuestCustomerAddressResolver::class)
        ->arg('$countryRepository', service('country.repository'));

    $services->set(ShopwareOrderGateway::class)
        ->arg('$orderRepository', service('order.repository'));

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
        ->public()
        ->arg('$salesChannelRepository', service('sales_channel.repository'))
        ->tag('controller.service_arguments');

    $services->set(UcpAdminController::class)
        ->public()
        ->tag('controller.service_arguments');

    $services->set(WebhookCaptureStore::class)
        ->arg('$projectDir', param('kernel.project_dir'));

    $services->set(TestWebhookController::class)
        ->public()
        ->arg('$appEnv', param('kernel.environment'))
        ->arg('$testCaptureEnabled', env('bool:default:defaults_bool_false:SWAG_AGENTIC_COMMERCE_TEST_CAPTURE'))
        ->tag('controller.service_arguments');

    $services->set(FallbackAgenticFileController::class)
        ->public()
        ->tag('controller.service_arguments');

    // Config layer.

    $services->alias(UcpConfigRepositoryInterface::class, DoctrineDbalUcpConfigRepository::class);
    $services->alias(LegacyConfigStoreInterface::class, SystemConfigLegacyConfigStore::class);
    $services->alias(RuntimeConfigurationResolverInterface::class, ShopwareRuntimeConfigurationResolver::class);
    $services->alias(DiscoveryBridgeInterface::class, TrunkDiscoveryBridge::class);
    $services->alias(AgenticFilesCoreBridgeInterface::class, CoreSalesChannelFileBridge::class);

    // Event listeners.

    $services->set(EmbeddedResponseListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest'])
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse', 'priority' => -1024]);

    $services->set(CoreSalesChannelFileSyncSubscriber::class)
        ->tag('kernel.event_subscriber');
};
