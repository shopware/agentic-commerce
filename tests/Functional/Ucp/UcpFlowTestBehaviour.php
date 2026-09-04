<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Test\Product\ProductBuilder;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Test\StaticAgentProfileFetcher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\ProfileBuilderInterface;

/**
 * Shared setup for UCP runtime-capability functional tests (catalog/cart/checkout).
 *
 * A real UCP runtime request must pass the SDK request-context handshake: a UCP-Agent header
 * pointing at an agent profile whose capabilities intersect the merchant's. Against the deployed
 * stack the smoke points the agent header at the merchant's own /.well-known/ucp (self-referential)
 * and fetches it over HTTP. Offline we reproduce that without the network:
 *
 *  - set the sales-channel config the smoke sets (active + non-strict signature policy);
 *  - build the merchant PlatformProfile (with its enabled capabilities) and hand it to the
 *    {@see StaticAgentProfileFetcher} that replaces the SDK's HTTP fetcher in the `test`
 *    environment (wired by {@see \Swag\AgenticCommerce\DependencyInjection\TestAgentProfileFetcherCompilerPass}),
 *    so context-building negotiates the full capability set without an HTTP fetch. The stub
 *    (rather than the SDK's profile-cache) is deliberate: the real fetcher runs an SSRF
 *    URL-safety check that rejects the lane's `*.localhost` host (only literal localhost/127.0.0.1
 *    are exempt), so the cache path can't be exercised from a local lane regardless of scheme.
 *
 * Requests go through a real Symfony {@see KernelBrowser} (the full HttpKernel request/response
 * cycle, kernel events included) against `APP_URL` — the test database's default storefront
 * sales-channel domain — exactly as Shopware's own functional tests do.
 */
trait UcpFlowTestBehaviour
{
    use IntegrationTestBehaviour;

    private KernelBrowser $browser;

    private string $ucpDomain;

    private string $ucpSalesChannelId;

    /**
     * Configures the storefront sales channel at APP_URL for UCP runtime traffic and hands the
     * agent-profile fetcher the merchant's own capability-bearing profile. Call from the test body.
     */
    protected function configureUcpRuntime(): void
    {
        $this->browser = KernelLifecycleManager::createBrowser($this->getKernel());
        $container = static::getContainer();

        // APP_URL is the test database's default storefront sales-channel domain (the same value
        // Shopware's own functional tests build their request URLs from).
        $this->ucpDomain = rtrim((string) EnvironmentHelper::getVariable('APP_URL'), '/');
        $salesChannelId = $container->get(Connection::class)->fetchOne(
            'SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain WHERE url = :url LIMIT 1',
            ['url' => $this->ucpDomain]
        );
        self::assertIsString($salesChannelId, \sprintf('Expected a storefront sales-channel domain at APP_URL (%s).', $this->ucpDomain));
        $this->ucpSalesChannelId = $salesChannelId;

        $config = $container->get(SystemConfigService::class);
        $config->set('SwagAgenticCommerce.config.active', true, $this->ucpSalesChannelId);
        $config->set('SwagAgenticCommerce.config.signaturePolicy', 'log', $this->ucpSalesChannelId);
        // The completed-checkout response requires order.permalink_url, which is built from the
        // continue-url template; set it (as the smoke does) before any config read is cached.
        $config->set('SwagAgenticCommerce.config.continueUrlTemplate', $this->ucpDomain.'/checkout/confirm?checkoutId={checkoutId}', $this->ucpSalesChannelId);

        $container->get(StaticAgentProfileFetcher::class)->setProfile($this->buildMerchantProfile());
    }

    /**
     * Creates a minimal active storefront product visible in the configured sales channel via the
     * core {@see ProductBuilder} fixture. Returns the product id.
     */
    protected function seedStorefrontProduct(string $name = 'Kernel Test Album', string $productNumber = 'UCP-IT-1'): string
    {
        // IdsCollection moved to Test\Stub\Framework in 6.6; the plugin still supports 6.5.
        $ids = class_exists(\Shopware\Core\Test\Stub\Framework\IdsCollection::class)
            ? new \Shopware\Core\Test\Stub\Framework\IdsCollection()
            : new \Shopware\Core\Framework\Test\IdsCollection();

        $product = (new ProductBuilder($ids, $productNumber, 100))
            ->name($name)
            ->price(19.99, 16.80)
            ->visibility($this->ucpSalesChannelId)
            ->build();

        static::getContainer()->get('product.repository')->create([$product], Context::createDefaultContext());

        return $product['id'];
    }

    /**
     * Drives a UCP runtime route through a real Symfony browser with a valid UCP-Agent header and a
     * fresh idempotency key. Each call starts from a clean cookie jar so requests stay independent,
     * mirroring the curl-based smoke.
     *
     * @param array<string, mixed>|null $json
     * @param array<string, string>     $extraServer extra server params, e.g. ['HTTP_SW_CONTEXT_TOKEN' => '...']
     */
    protected function ucpRequest(string $method, string $path, ?array $json = null, array $extraServer = []): Response
    {
        $server = [
            'HTTP_UCP_AGENT' => \sprintf('platform; profile="%s/.well-known/ucp"', $this->ucpDomain),
            'HTTP_IDEMPOTENCY_KEY' => 'it-'.Uuid::randomHex(),
        ] + $extraServer;
        $body = null;
        if (null !== $json) {
            $server['CONTENT_TYPE'] = 'application/json';
            $body = json_encode($json, \JSON_THROW_ON_ERROR);
        }

        $this->browser->getCookieJar()->clear();
        $this->browser->request($method, $this->ucpDomain.$path, [], [], $server, $body);

        return $this->browser->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * The Shopware context token persisted for a completed checkout, used to authorize the secured
     * order read (mirrors the smoke's sales_channel_api_context lookup).
     */
    protected function completedCheckoutContextToken(string $checkoutId): string
    {
        $token = static::getContainer()->get(Connection::class)->fetchOne(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, '$.swagAgenticCommerce.ucpCheckout.shopwareContextToken'))
             FROM sales_channel_api_context WHERE sales_channel_id = UNHEX(:scId) AND token = :checkoutId LIMIT 1",
            ['scId' => $this->ucpSalesChannelId, 'checkoutId' => $checkoutId]
        );
        self::assertIsString($token, 'Expected a persisted Shopware context token for the completed checkout.');

        return $token;
    }

    private function buildMerchantProfile(): PlatformProfile
    {
        $container = static::getContainer();
        $config = $container->get(UcpConfigService::class)->getConfig($this->ucpSalesChannelId);
        $storeApiMcp = $container->get(ShopwareVersionDetector::class)->supportsStoreApiMcp();

        return $container->get(ProfileBuilderInterface::class)->build(new ProfileBuildInput(
            $config::ucpVersion(),
            $config->resolveBaseUri($this->ucpDomain),
            $config->runtimeTransports($storeApiMcp),
            transportEndpoints: $config->transportEndpoints($this->ucpDomain, $storeApiMcp),
            tenantIdentifier: $this->ucpSalesChannelId,
            enabledCapabilities: $config->runtimeEnabledCapabilityDescriptors(),
        ));
    }
}
