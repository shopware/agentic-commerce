<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\Ucp;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives a real UCP runtime route through the booted test kernel — the first kernel
 * integration test in this plugin. It proves the SDK request-time validation
 * (DefaultHttpRequestContextFactory) rejects a runtime request that omits the UCP-Agent
 * header with a 422, replacing the equivalent shell-smoke assertion with a readable PHP test.
 *
 * Requires the full test-kernel boot (TestBootstrap.php fallback path: SHOPWARE_PROJECT_DIR
 * unset + APP_ENV=test); skipped under the lightweight fast-path bootstrap the mock-based suite
 * uses, where no kernel is available.
 *
 * @internal
 */
final class UcpRequestContextGuardTest extends TestCase
{
    use IntegrationTestBehaviour;

    public static function setUpBeforeClass(): void
    {
        $projectDir = getenv('SHOPWARE_PROJECT_DIR');
        if (\is_string($projectDir) && '' !== $projectDir && is_dir($projectDir)) {
            self::markTestSkipped('Kernel integration test requires the booting bootstrap (SHOPWARE_PROJECT_DIR unset).');
        }
    }

    #[Test]
    public function testRuntimeRequestWithoutUcpAgentHeaderIsRejected(): void
    {
        $domain = static::getContainer()->get(Connection::class)
            ->fetchOne("SELECT url FROM sales_channel_domain WHERE url LIKE 'http%' ORDER BY url LIMIT 1");
        self::assertIsString($domain, 'Expected a storefront sales-channel domain in the test database.');

        $request = Request::create($domain.'/ucp/v1/catalog/search', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'it-no-agent-'.uniqid('', true),
        ], '{"query":"smoke","limit":1}');

        $response = static::getKernel()->handle($request);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('$.headers.ucp-agent is required', $payload['messages'][0]['content'] ?? null);
    }

    #[Test]
    public function testOAuthMetadataStaysUnsupportedUntilIdentityLinkingIsEnabled(): void
    {
        $domain = static::getContainer()->get(Connection::class)
            ->fetchOne("SELECT url FROM sales_channel_domain WHERE url LIKE 'http%' ORDER BY url LIMIT 1");
        self::assertIsString($domain, 'Expected a storefront sales-channel domain in the test database.');

        $response = static::getKernel()->handle(
            Request::create($domain.'/.well-known/oauth-authorization-server')
        );

        self::assertSame(Response::HTTP_NOT_IMPLEMENTED, $response->getStatusCode());
    }
}
