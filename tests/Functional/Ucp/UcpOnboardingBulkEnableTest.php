<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Test\TestCaseBase\AdminFunctionalTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the onboarding bulk-enable route through the booted test kernel with an authenticated
 * admin browser.
 *
 * Proves the two things the dialog depends on and a unit test cannot: that activating a channel
 * really persists the config and provisions a signing key through the existing
 * {@see UcpConfigService}, and that a channel the config service refuses comes back as that row's
 * failure with the batch still reporting 200.
 *
 * @internal
 */
final class UcpOnboardingBulkEnableTest extends TestCase
{
    use AdminFunctionalTestBehaviour;

    private const ROUTE = '/api/_admin/ucp/onboarding/bulk-enable';

    private const READINESS_ROUTE = '/api/_admin/ucp/onboarding/readiness';

    #[Test]
    public function testItExposesTheStorefrontChannelAndGivesItASigningKey(): void
    {
        $salesChannelId = $this->storefrontSalesChannelId();

        $this->getBrowser()->request('POST', self::ROUTE, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode([
            'salesChannelIds' => [$salesChannelId],
            'enabledCapabilities' => ['catalog', 'cart'],
            'enabledTransports' => ['rest'],
        ]));

        $response = $this->getBrowser()->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response->getContent());
        self::assertSame(1, $data['enabled']);
        self::assertSame(0, $data['failed']);
        self::assertSame('enabled', $data['results'][0]['status']);

        $config = static::getContainer()->get(UcpConfigService::class)->getConfig($salesChannelId);
        self::assertTrue($config->active);
        self::assertSame(['catalog', 'cart'], $config->enabledCapabilities);
        self::assertSame(['rest'], $config->enabledTransports);

        self::assertNotSame([], static::getContainer()->get(UcpSigningKeyService::class)->all($salesChannelId));
    }

    #[Test]
    public function testAnIneligibleChannelFailsItsOwnRowAndTheBatchStillSucceeds(): void
    {
        $salesChannelId = $this->storefrontSalesChannelId();
        $unknownId = Uuid::randomHex();

        $this->getBrowser()->request('POST', self::ROUTE, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode([
            'salesChannelIds' => [$unknownId, $salesChannelId],
            'enabledCapabilities' => ['catalog'],
            'enabledTransports' => ['rest'],
        ]));

        $response = $this->getBrowser()->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response->getContent());
        self::assertSame(1, $data['failed']);
        self::assertSame(1, $data['enabled']);

        self::assertSame('failed', $data['results'][0]['status']);
        self::assertSame('SWAG_AGENTIC_COMMERCE__UCP_SALES_CHANNEL_TYPE_NOT_SUPPORTED', $data['results'][0]['code']);
        self::assertSame('enabled', $data['results'][1]['status']);
    }

    #[Test]
    public function testReadinessReportsSetupNeededAndFlipsOnceAChannelIsPrepared(): void
    {
        $this->getBrowser()->request('GET', self::READINESS_ROUTE);
        $before = $this->decode($this->getBrowser()->getResponse()->getContent());

        self::assertSame('setup_needed', $before['status']);
        self::assertSame(1, $before['completedSteps']);
        self::assertTrue($before['steps']['installed']['done']);
        self::assertFalse($before['steps']['prepared']['done']);
        self::assertNotSame([], $before['channels']);

        $this->getBrowser()->request('POST', self::ROUTE, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode(['salesChannelIds' => [$this->storefrontSalesChannelId()]]));
        self::assertSame(Response::HTTP_OK, $this->getBrowser()->getResponse()->getStatusCode());

        $this->getBrowser()->request('GET', self::READINESS_ROUTE);
        $after = $this->decode($this->getBrowser()->getResponse()->getContent());

        self::assertTrue($after['steps']['prepared']['done']);
        self::assertSame(1, $after['steps']['prepared']['count']);
        // No Agentic Commerce sales channel exists, so step three is still open.
        self::assertSame('action_needed', $after['status']);
    }

    #[Test]
    public function testAnUnknownCapabilityIsRejectedBeforeAnythingIsWritten(): void
    {
        $this->getBrowser()->request('POST', self::ROUTE, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode([
            'salesChannelIds' => [$this->storefrontSalesChannelId()],
            'enabledCapabilities' => ['teleportation'],
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->getBrowser()->getResponse()->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string|false $content): array
    {
        $payload = json_decode((string) $content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['data'] ?? null);

        return $payload['data'];
    }

    private function storefrontSalesChannelId(): string
    {
        $url = rtrim((string) EnvironmentHelper::getVariable('APP_URL'), '/');
        $salesChannelId = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain WHERE url = :url LIMIT 1',
            ['url' => $url],
        );
        self::assertIsString($salesChannelId, \sprintf('Expected a storefront sales-channel domain at APP_URL (%s).', $url));

        return $salesChannelId;
    }
}
