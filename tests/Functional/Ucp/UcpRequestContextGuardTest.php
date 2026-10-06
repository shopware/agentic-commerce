<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives a real UCP runtime route through the booted test kernel via a Symfony browser — the first
 * functional test in this plugin. It proves the SDK request-time validation
 * (DefaultHttpRequestContextFactory) rejects a runtime request that omits the UCP-Agent header with
 * a 422, replacing the equivalent shell-smoke assertion with a readable PHP test, and that the
 * OAuth-metadata endpoint stays a 501 stub until identity linking is enabled.
 *
 * The strict-policy tests persist `signaturePolicy=strict` through UcpConfigService (the service behind
 * `ucp:config:set`), overriding the `log` system config from {@see UcpFlowTestBehaviour::configureUcpRuntime()}.
 * A2A stays incomplete while the SDK's RequestContextListener::isUcpRequest() exempts that path. The
 * embedded page must still be served: browsers cannot sign an iframe load, and the Embedded Checkout
 * Protocol authorizes it through the token in the URL.
 *
 * Requests target `APP_URL` — the test database's default storefront sales-channel domain — exactly
 * as Shopware's own functional tests do.
 *
 * @internal
 */
final class UcpRequestContextGuardTest extends TestCase
{
    use UcpFlowTestBehaviour;

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

    #[Test]
    public function testUnsignedCatalogSearchIsRejectedUnderStrictSignaturePolicy(): void
    {
        $this->configureUcpRuntime();
        $this->enforceStrictSignaturePolicy();

        $response = $this->ucpRequest('POST', '/ucp/v1/catalog/search', ['query' => 'Kernel', 'limit' => 1]);

        $this->assertMissingSignatureRejection($response);
    }

    #[Test]
    public function testUnsignedA2aRequestIsRejectedUnderStrictSignaturePolicy(): void
    {
        $this->configureUcpRuntime();
        $this->enforceStrictSignaturePolicy();

        $response = $this->ucpRequest('POST', '/ucp/a2a', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'catalog.search',
            'params' => ['query' => 'Kernel', 'limit' => 1],
        ]);

        // JSON-RPC errors are also HTTP 200, so only a served result counts as the known gap.
        if (Response::HTTP_OK === $response->getStatusCode() && isset($this->decode($response)['result'])) {
            self::markTestIncomplete('ucp-php-sdk 0.0.7 RequestContextListener::isUcpRequest() excludes /ucp/a2a from request-context handling, so signaturePolicy=strict is not enforced on A2A.');
        }

        $this->assertMissingSignatureRejection($response);
    }

    #[Test]
    public function testUnsignedEmbeddedCartPageIsServedUnderStrictSignaturePolicy(): void
    {
        $this->configureUcpRuntime();
        $productId = $this->seedStorefrontProduct('Kernel Test Album');
        $create = $this->ucpRequest('POST', '/ucp/v1/carts', [
            'line_items' => [['item' => ['id' => $productId, 'title' => 'Kernel Test Album', 'price' => 19.99], 'quantity' => 1]],
        ]);
        self::assertSame(Response::HTTP_CREATED, $create->getStatusCode());
        $cartId = $this->decode($create)['id'];
        $this->enforceStrictSignaturePolicy();

        $response = $this->ucpRequest('GET', '/ucp/embedded/cart/'.$cartId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    }

    private function enforceStrictSignaturePolicy(): void
    {
        $config = static::getContainer()->get(UcpConfigService::class)->saveConfig([
            'signaturePolicy' => 'strict',
            'enabledTransports' => ['rest', 'a2a', 'embedded'],
            // EmbeddedResponseListener answers 403 before routing while no origin is allowlisted.
            'embeddedAllowedOrigins' => [$this->ucpDomain],
        ], $this->ucpSalesChannelId);

        self::assertSame('strict', $config->signaturePolicy);
    }

    private function assertMissingSignatureRejection(Response $response): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $message = $this->decode($response)['messages'][0] ?? [];
        self::assertSame('signature_invalid', $message['code'] ?? null);
        self::assertSame('Missing signature headers.', $message['content'] ?? null);
    }

    private function appUrl(): string
    {
        return rtrim((string) EnvironmentHelper::getVariable('APP_URL'), '/');
    }
}
