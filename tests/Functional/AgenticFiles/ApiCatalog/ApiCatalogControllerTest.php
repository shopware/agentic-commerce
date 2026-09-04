<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\AgenticFiles\ApiCatalog;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the `/.well-known/api-catalog` storefront route through the booted test kernel via a real
 * Symfony browser against APP_URL — the test database's default storefront sales-channel domain,
 * exactly as the plugin's other functional tests do.
 *
 * @internal
 */
final class ApiCatalogControllerTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testItServesTheLinksetForAnExposedSalesChannel(): void
    {
        $this->setUcpActive(true);

        $browser = KernelLifecycleManager::createBrowser($this->getKernel());
        $browser->request('GET', $this->baseUrl().'/.well-known/api-catalog');
        $response = $browser->getResponse();

        static::assertSame(Response::HTTP_OK, $response->getStatusCode());
        static::assertSame(
            'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"',
            $response->headers->get('Content-Type'),
        );
        static::assertSame(
            '<'.$this->baseUrl().'/.well-known/api-catalog>; rel="api-catalog"',
            $response->headers->get('Link'),
        );

        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame([
            'linkset' => [
                [
                    'anchor' => $this->baseUrl().'/.well-known/api-catalog',
                    'service-meta' => [
                        ['href' => $this->baseUrl().'/.well-known/ucp', 'type' => 'application/json'],
                    ],
                    'item' => [
                        ['href' => $this->baseUrl().'/store-api', 'type' => 'application/json'],
                    ],
                ],
            ],
        ], $payload);
    }

    public function testItReturns404ForAnUnexposedSalesChannel(): void
    {
        // The sales channel is not exposed for agentic commerce (UCP config inactive).
        $browser = KernelLifecycleManager::createBrowser($this->getKernel());
        $browser->request('GET', $this->baseUrl().'/.well-known/api-catalog');

        static::assertSame(Response::HTTP_NOT_FOUND, $browser->getResponse()->getStatusCode());
    }

    private function setUcpActive(bool $active): void
    {
        $config = static::getContainer()->get(SystemConfigService::class);
        static::assertInstanceOf(SystemConfigService::class, $config);

        $config->set('SwagAgenticCommerce.config.active', $active, $this->salesChannelId());
    }

    private function salesChannelId(): string
    {
        $connection = static::getContainer()->get(Connection::class);
        static::assertInstanceOf(Connection::class, $connection);

        // APP_URL is the test database's default storefront sales-channel domain.
        $salesChannelId = $connection->fetchOne(
            'SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain WHERE url = :url LIMIT 1',
            ['url' => $this->baseUrl()],
        );
        static::assertIsString($salesChannelId, \sprintf('Expected a storefront sales-channel domain at APP_URL (%s).', $this->baseUrl()));

        return $salesChannelId;
    }

    private function baseUrl(): string
    {
        return rtrim((string) EnvironmentHelper::getVariable('APP_URL'), '/');
    }
}
