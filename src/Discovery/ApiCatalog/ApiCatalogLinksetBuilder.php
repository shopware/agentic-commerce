<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Discovery\ApiCatalog;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Ucp\Sdk\Enum\Transport;

/**
 * Builds the RFC 9727 API catalog as an RFC 9264 Linkset document.
 *
 * Only surfaces that are actually reachable on the current lane and sales-channel
 * configuration are listed, following the plugin's rule that unsupported
 * capabilities are hidden rather than advertised as dead links.
 *
 * @internal
 */
#[Package('discovery')]
final class ApiCatalogLinksetBuilder
{
    public const CATALOG_PATH = '/.well-known/api-catalog';

    private const UCP_PATH = '/ucp';

    private const UCP_A2A_PATH = '/ucp/a2a';

    private const UCP_PROFILE_PATH = '/.well-known/ucp';

    private const AGENT_CARD_PATH = '/.well-known/agent-card.json';

    private const STORE_API_PATH = '/store-api';

    private const STORE_API_DESCRIPTION_PATH = '/store-api/_info/openapi3.json';

    private const JSON_MEDIA_TYPE = 'application/json';

    public function __construct(
        private readonly ShopwareVersionDetector $versionDetector,
    ) {
    }

    /**
     * @return array{linkset: non-empty-list<array<string, mixed>>}
     */
    public function build(UcpConfig $config, string $fallbackBaseUri): array
    {
        $baseUri = $config->resolveBaseUri($fallbackBaseUri);
        $storeApiMcpAvailable = $this->versionDetector->supportsStoreApiMcp();

        $items = [
            $this->target($baseUri.self::UCP_PATH, 'Universal Commerce Protocol'),
            $this->target($baseUri.self::STORE_API_PATH, 'Shopware Store API'),
        ];

        $mcpEndpoint = $config->transportEndpoints($fallbackBaseUri, $storeApiMcpAvailable)[Transport::Mcp->value] ?? null;
        if (\is_string($mcpEndpoint)) {
            $items[] = $this->target($mcpEndpoint, 'UCP Model Context Protocol endpoint');
        }

        $linkset = [
            [
                'anchor' => $baseUri.self::CATALOG_PATH,
                'item' => $items,
            ],
            [
                'anchor' => $baseUri.self::UCP_PATH,
                'service-meta' => [
                    $this->target($baseUri.self::UCP_PROFILE_PATH, 'UCP platform profile', self::JSON_MEDIA_TYPE),
                ],
            ],
        ];

        // The agent card is served by the SDK's A2aController, which 404s unless the
        // A2A transport is enabled for the channel, so only list it when it resolves.
        if (\in_array(Transport::A2a, $config->runtimeTransports($storeApiMcpAvailable), true)) {
            $linkset[] = [
                'anchor' => $baseUri.self::UCP_A2A_PATH,
                'service-meta' => [
                    $this->target($baseUri.self::AGENT_CARD_PATH, 'A2A agent card', self::JSON_MEDIA_TYPE),
                ],
            ];
        }

        $linkset[] = [
            'anchor' => $baseUri.self::STORE_API_PATH,
            'service-desc' => [
                $this->target($baseUri.self::STORE_API_DESCRIPTION_PATH, 'Store API OpenAPI 3 description', self::JSON_MEDIA_TYPE),
            ],
        ];

        return ['linkset' => $linkset];
    }

    /**
     * @return array<string, string>
     */
    private function target(string $href, string $title, ?string $type = null): array
    {
        $target = ['href' => $href];

        if (null !== $type) {
            $target['type'] = $type;
        }

        $target['title'] = $title;

        return $target;
    }
}
