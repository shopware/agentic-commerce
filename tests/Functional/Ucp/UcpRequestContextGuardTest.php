<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives a real UCP runtime route through the booted test kernel via a Symfony browser — the first
 * functional test in this plugin. It proves the SDK request-time validation
 * (DefaultHttpRequestContextFactory) rejects a runtime request that omits the UCP-Agent header with
 * a 422, replacing the equivalent shell-smoke assertion with a readable PHP test, and that the
 * OAuth-metadata endpoint stays a 501 stub until identity linking is enabled.
 *
 * Requests target `APP_URL` — the test database's default storefront sales-channel domain — exactly
 * as Shopware's own functional tests do.
 *
 * @internal
 */
final class UcpRequestContextGuardTest extends TestCase
{
    use IntegrationTestBehaviour;

    #[Test]
    public function testRuntimeRequestWithoutUcpAgentHeaderIsRejected(): void
    {
        $browser = KernelLifecycleManager::createBrowser($this->getKernel());

        $browser->request('POST', $this->appUrl().'/ucp/v1/catalog/search', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'it-no-agent-'.uniqid('', true),
        ], '{"query":"smoke","limit":1}');

        $response = $browser->getResponse();

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('$.headers.ucp-agent is required', $payload['messages'][0]['content'] ?? null);
    }

    #[Test]
    public function testOAuthMetadataStaysUnsupportedUntilIdentityLinkingIsEnabled(): void
    {
        $browser = KernelLifecycleManager::createBrowser($this->getKernel());

        $browser->request('GET', $this->appUrl().'/.well-known/oauth-authorization-server');

        self::assertSame(Response::HTTP_NOT_IMPLEMENTED, $browser->getResponse()->getStatusCode());
    }

    private function appUrl(): string
    {
        return rtrim((string) EnvironmentHelper::getVariable('APP_URL'), '/');
    }
}
