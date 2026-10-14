<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\AgenticFiles\Readiness;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the readiness Admin API routes through the booted test kernel, so the route import, the
 * container wiring and the ACL defaults are exercised the way the Administration calls them.
 *
 * @internal
 */
final class BasicAiReadinessControllerTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    private const STATUS_URL = '/api/_admin/agentic-commerce/readiness/status';
    private const ENABLE_URL = '/api/_admin/agentic-commerce/readiness/enable';

    public function testStatusIsForbiddenWithoutTheSalesChannelFilePrivilege(): void
    {
        $browser = $this->getBrowser(true, [], ['sales_channel:read']);
        $browser->request('GET', self::STATUS_URL);

        static::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode());
    }

    public function testStatusIsForbiddenWithoutTheSalesChannelPrivilege(): void
    {
        $browser = $this->getBrowser(true, [], ['sales_channel_file:read']);
        $browser->request('GET', self::STATUS_URL);

        static::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode());
    }

    public function testEnableIsForbiddenWithReadPrivilegesOnly(): void
    {
        $browser = $this->getBrowser(true, [], ['sales_channel:read', 'sales_channel_file:read']);
        $browser->request('POST', self::ENABLE_URL, [], [], [], '{}');

        static::assertSame(Response::HTTP_FORBIDDEN, $browser->getResponse()->getStatusCode());
    }

    public function testEnableRunsWithTheDocumentedPrivileges(): void
    {
        $browser = $this->getBrowser(true, [], ['sales_channel:read', 'sales_channel_file:read', 'sales_channel_file:create']);
        $browser->request('POST', self::ENABLE_URL, [], [], [], (string) json_encode(['dryRun' => true]));

        $response = $browser->getResponse();
        static::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        static::assertIsArray($payload);
        static::assertSame(CoreSalesChannelFileFeature::isAvailableByClass(), $payload['data']['coreSupported']);
        static::assertTrue($payload['data']['dryRun']);
    }

    public function testEnableMakesTheStorefrontServeLlmsTxtWithoutUcp(): void
    {
        if (!CoreSalesChannelFileFeature::isAvailableByClass()) {
            static::markTestSkipped('Core serves the files from 6.7.12 on; older versions change nothing.');
        }

        $salesChannelId = $this->storefrontSalesChannelId();

        $this->getBrowser()->request('POST', self::ENABLE_URL, [], [], [], (string) json_encode(['salesChannelIds' => [$salesChannelId]]));
        static::assertSame(Response::HTTP_OK, $this->getBrowser()->getResponse()->getStatusCode(), (string) $this->getBrowser()->getResponse()->getContent());

        $storefront = KernelLifecycleManager::createBrowser($this->getKernel());
        $storefront->request('GET', $this->baseUrl().'/llms.txt');
        $response = $storefront->getResponse();

        static::assertSame(Response::HTTP_OK, $response->getStatusCode());
        static::assertStringNotContainsString('/.well-known/ucp', (string) $response->getContent());
    }

    private function storefrontSalesChannelId(): string
    {
        $connection = static::getContainer()->get(Connection::class);
        static::assertInstanceOf(Connection::class, $connection);

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
