<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the RFC 9727 API catalog through the booted test kernel via a Symfony browser.
 *
 * Unlike the UCP runtime routes this is a plain storefront GET — no UCP-Agent handshake — so the
 * test only has to toggle the channel's UCP exposure to prove both halves of the contract: an
 * exposed channel serves the linkset, an unexposed one 404s.
 *
 * @internal
 */
final class ApiCatalogDiscoveryTest extends TestCase
{
    use IntegrationTestBehaviour;

    #[Test]
    public function testExposedSalesChannelServesTheLinkset(): void
    {
        $this->exposeUcp();

        $browser = KernelLifecycleManager::createBrowser($this->getKernel());
        $browser->request('GET', $this->appUrl().'/.well-known/api-catalog');
        $response = $browser->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringStartsWith('application/linkset+json', (string) $response->headers->get('content-type'));

        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $hrefs = $this->hrefs($payload['linkset'] ?? []);

        self::assertContains($this->appUrl().'/.well-known/ucp', $hrefs, 'The UCP profile must be discoverable from the catalog.');
        self::assertContains($this->appUrl().'/store-api', $hrefs, 'The Store API entry point must be discoverable from the catalog.');
    }

    #[Test]
    public function testUnexposedSalesChannelIsNotFound(): void
    {
        // IntegrationTestBehaviour wraps each test in a transaction, so the channel starts unexposed.
        $browser = KernelLifecycleManager::createBrowser($this->getKernel());
        $browser->request('GET', $this->appUrl().'/.well-known/api-catalog');

        self::assertSame(Response::HTTP_NOT_FOUND, $browser->getResponse()->getStatusCode());
    }

    private function exposeUcp(): void
    {
        $salesChannelId = static::getContainer()->get(\Doctrine\DBAL\Connection::class)->fetchOne(
            'SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain WHERE url = :url LIMIT 1',
            ['url' => $this->appUrl()]
        );
        self::assertIsString($salesChannelId, \sprintf('Expected a storefront sales-channel domain at APP_URL (%s).', $this->appUrl()));

        static::getContainer()->get(SystemConfigService::class)
            ->set('SwagAgenticCommerce.config.active', true, $salesChannelId);
    }

    /**
     * Every href in the linkset, regardless of which link context or relation it sits under.
     *
     * @param array<int, array<string, mixed>> $linkset
     *
     * @return list<string>
     */
    private function hrefs(array $linkset): array
    {
        $hrefs = [];

        foreach ($linkset as $linkContext) {
            foreach ($linkContext as $relation => $targets) {
                if ('anchor' === $relation || !\is_array($targets)) {
                    continue;
                }

                foreach ($targets as $target) {
                    if (\is_array($target) && \is_string($target['href'] ?? null)) {
                        $hrefs[] = $target['href'];
                    }
                }
            }
        }

        return $hrefs;
    }

    private function appUrl(): string
    {
        return rtrim((string) EnvironmentHelper::getVariable('APP_URL'), '/');
    }
}
