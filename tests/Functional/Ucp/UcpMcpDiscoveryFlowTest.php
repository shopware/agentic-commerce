<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Mcp\UcpMcpToolset;
use Symfony\Component\HttpFoundation\Response;

/**
 * A UCP agent connecting to `/ucp/mcp` must see the UCP tools on its first `tools/list`, while a
 * plain `/store-api/_mcp` connection keeps core's default surface (the discovery meta-tools only).
 * The proxy gets there by pinning the UCP toolset at connect time (shopware/agentic-commerce#254).
 *
 * @internal
 */
final class UcpMcpDiscoveryFlowTest extends TestCase
{
    use UcpFlowTestBehaviour;

    private const ACCEPT = 'application/json, text/event-stream';

    protected function setUp(): void
    {
        if (!UcpMcpToolset::coreSupportsConnectTimeToolsets() || !static::getContainer()->get(ShopwareVersionDetector::class)->supportsStoreApiMcp()) {
            self::markTestSkipped('Needs the Store API MCP endpoint with connect-time toolset selection (Shopware 6.7.15.0+).');
        }
    }

    #[Test]
    public function testUcpToolsAreListedOnAFreshUcpMcpSession(): void
    {
        $this->configureUcpRuntime();
        static::getContainer()->get(UcpConfigService::class)->saveConfig(['enabledTransports' => ['rest', 'mcp']], $this->ucpSalesChannelId);

        $tools = $this->listTools('/ucp/mcp', []);

        self::assertContains('search_catalog', $tools);
        self::assertContains('create_cart', $tools);
        self::assertContains('get_cart', $tools);
    }

    #[Test]
    public function testAPlainStoreApiSessionOnlyAdvertisesTheDiscoveryTools(): void
    {
        $this->configureUcpRuntime();
        $accessKey = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT access_key FROM sales_channel WHERE id = UNHEX(:id)',
            ['id' => $this->ucpSalesChannelId],
        );
        self::assertIsString($accessKey);

        $tools = $this->listTools('/store-api/_mcp', ['HTTP_SW_ACCESS_KEY' => $accessKey]);

        self::assertNotContains('search_catalog', $tools, 'The UCP tools must not be on every Store API connection.');
        self::assertContains('shopware-toolsets-list', $tools);
    }

    /**
     * Opens a fresh MCP session on $path and returns the names of the tools on its first page.
     *
     * @param array<string, string> $server
     *
     * @return list<string>
     */
    private function listTools(string $path, array $server): array
    {
        $server += ['HTTP_ACCEPT' => self::ACCEPT];

        $initialize = $this->ucpRequest('POST', $path, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'ucp-mcp-discovery-test', 'version' => '1.0'],
            ],
        ], $server);
        self::assertSame(Response::HTTP_OK, $initialize->getStatusCode(), (string) $initialize->getContent());

        $sessionId = $initialize->headers->get('mcp-session-id');
        self::assertIsString($sessionId, 'initialize must return an MCP session id.');
        $server['HTTP_MCP_SESSION_ID'] = $sessionId;

        $this->ucpRequest('POST', $path, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $server);

        $list = $this->ucpRequest('POST', $path, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass()], $server);
        self::assertSame(Response::HTTP_OK, $list->getStatusCode(), (string) $list->getContent());

        $message = $this->lastJsonRpcMessage((string) $list->getContent());
        self::assertIsArray($message['result']['tools'] ?? null, (string) $list->getContent());

        return array_values(array_map(static fn (array $tool): string => $tool['name'], $message['result']['tools']));
    }

    /**
     * The answer arrives as a single JSON object, or as SSE `data:` frames when notifications ride along.
     *
     * @return array<string, mixed>
     */
    private function lastJsonRpcMessage(string $body): array
    {
        $decoded = json_decode($body, true);
        if (\is_array($decoded)) {
            return $decoded;
        }

        $message = [];
        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'data:')) {
                $frame = json_decode(trim(substr($line, 5)), true);
                if (\is_array($frame) && isset($frame['result'])) {
                    $message = $frame;
                }
            }
        }

        return $message;
    }
}
