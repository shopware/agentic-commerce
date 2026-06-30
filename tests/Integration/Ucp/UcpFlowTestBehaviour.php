<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\Ucp;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;
use Ucp\Sdk\Service\ProfileBuilderInterface;

/**
 * Shared setup for UCP runtime-capability kernel tests (catalog/cart/checkout).
 *
 * A real UCP runtime request must pass the SDK request-context handshake: a UCP-Agent header
 * pointing at an agent profile whose capabilities intersect the merchant's. Against the deployed
 * stack the smoke points the agent header at the merchant's own /.well-known/ucp (self-referential)
 * and fetches it over HTTP. Offline we reproduce that without the network:
 *
 *  - set the sales-channel config the smoke sets (active + non-strict signature policy);
 *  - build the merchant PlatformProfile (with its enabled capabilities) and return it from a
 *    stubbed {@see AgentProfileFetcherInterface}, so context-building negotiates the full
 *    capability set without an HTTP fetch. The stub (rather than the SDK's profile-cache) is
 *    deliberate: the real fetcher runs an SSRF URL-safety check that rejects the lane's
 *    `*.localhost` host (only literal localhost/127.0.0.1 are exempt), so the cache path can't
 *    be exercised from a local lane regardless of scheme.
 *
 * `configureUcpRuntime()` reboots the kernel first (reusing the DB connection, so the
 * transaction-rollback isolation is preserved) so the stub can replace a not-yet-initialized
 * service even though the test kernel is shared across the suite.
 *
 * Requires the booting bootstrap (SHOPWARE_PROJECT_DIR unset + APP_ENV=test); self-skips otherwise.
 */
trait UcpFlowTestBehaviour
{
    use IntegrationTestBehaviour;

    private string $ucpDomain;

    private string $ucpSalesChannelId;

    public static function setUpBeforeClass(): void
    {
        $projectDir = getenv('SHOPWARE_PROJECT_DIR');
        if (\is_string($projectDir) && '' !== $projectDir && is_dir($projectDir)) {
            self::markTestSkipped('UCP flow kernel test requires the booting bootstrap (SHOPWARE_PROJECT_DIR unset).');
        }
    }

    /**
     * Configures the first storefront sales channel for UCP runtime traffic and replaces the agent
     * profile fetcher with the merchant's own capability-bearing profile. Call from the test body.
     */
    protected function configureUcpRuntime(): void
    {
        // Fresh container so the agent-fetcher service is replaceable (the suite shares one kernel);
        // reuseConnection keeps the open test transaction so DB writes still roll back.
        KernelLifecycleManager::bootKernel(true);
        $container = static::getContainer();

        $domainRow = $container->get(Connection::class)->fetchAssociative(
            "SELECT url, LOWER(HEX(sales_channel_id)) AS salesChannelId
             FROM sales_channel_domain WHERE url LIKE 'http%' ORDER BY url LIMIT 1"
        );
        self::assertIsArray($domainRow, 'Expected a storefront sales-channel domain in the test database.');

        $this->ucpDomain = (string) $domainRow['url'];
        $this->ucpSalesChannelId = (string) $domainRow['salesChannelId'];

        $config = $container->get(SystemConfigService::class);
        $config->set('SwagAgenticCommerce.config.active', true, $this->ucpSalesChannelId);
        $config->set('SwagAgenticCommerce.config.signaturePolicy', 'log', $this->ucpSalesChannelId);

        $container->set(AgentProfileFetcherInterface::class, new class($this->buildMerchantProfile()) implements AgentProfileFetcherInterface {
            public function __construct(private readonly PlatformProfile $profile)
            {
            }

            public function fetch(string $uri): PlatformProfile
            {
                return $this->profile;
            }
        });
    }

    /**
     * Creates a minimal active storefront product visible in the configured sales channel, mirroring
     * SeedSmokeCatalogCommand. Returns the product id.
     */
    protected function seedStorefrontProduct(string $name = 'Kernel Test Album', string $productNumber = 'UCP-IT-1'): string
    {
        $context = Context::createDefaultContext();
        $taxId = static::getContainer()->get('tax.repository')->searchIds((new Criteria())->setLimit(1), $context)->firstId();
        self::assertIsString($taxId, 'Expected a tax record in the test database.');

        $productId = Uuid::randomHex();
        static::getContainer()->get('product.repository')->upsert([[
            'id' => $productId,
            'productNumber' => $productNumber,
            'active' => true,
            'stock' => 100,
            'name' => $name,
            'taxId' => $taxId,
            'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 19.99, 'net' => 16.80, 'linked' => false]],
            'visibilities' => [['salesChannelId' => $this->ucpSalesChannelId, 'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL]],
        ]], $context);

        return $productId;
    }

    /**
     * Drives a UCP runtime route through the booted kernel with a valid UCP-Agent header and a fresh
     * idempotency key.
     *
     * @param array<string, mixed>|null $json
     */
    protected function ucpRequest(string $method, string $path, ?array $json = null): Response
    {
        $server = [
            'HTTP_UCP_AGENT' => \sprintf('platform; profile="%s/.well-known/ucp"', $this->ucpDomain),
            'HTTP_IDEMPOTENCY_KEY' => 'it-'.Uuid::randomHex(),
        ];
        $body = null;
        if (null !== $json) {
            $server['CONTENT_TYPE'] = 'application/json';
            $body = json_encode($json, \JSON_THROW_ON_ERROR);
        }

        return static::getKernel()->handle(Request::create($this->ucpDomain.$path, $method, [], [], [], $server, $body));
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function buildMerchantProfile(): PlatformProfile
    {
        $container = static::getContainer();
        $config = $container->get(UcpConfigService::class)->getConfig($this->ucpSalesChannelId);
        $storeApiMcp = $container->get(ShopwareVersionDetector::class)->supportsStoreApiMcp();

        return $container->get(ProfileBuilderInterface::class)->build(new ProfileBuildInput(
            $config->ucpVersion,
            $config->resolveBaseUri($this->ucpDomain),
            $config->runtimeTransports($storeApiMcp),
            transportEndpoints: $config->transportEndpoints($this->ucpDomain, $storeApiMcp),
            tenantIdentifier: $this->ucpSalesChannelId,
            enabledCapabilities: $config->runtimeEnabledCapabilityDescriptors(),
        ));
    }
}
