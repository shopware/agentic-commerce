<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\ApiCatalog;

use Shopware\Core\Framework\Api\ApiDefinition\DefinitionService;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\AgenticFiles\Fallback\FallbackAgenticFileRenderer;

/**
 * Builds the RFC 9727 `/.well-known/api-catalog` document as an RFC 9264 linkset,
 * enumerating the machine-readable API surfaces an agent can discover for a sales channel.
 *
 * @internal
 */
#[Package('discovery')]
final class ApiCatalogLinksetBuilder
{
    /**
     * Profile URI advertised on the linkset per RFC 9727 §4.1.
     */
    public const PROFILE_URI = 'https://www.rfc-editor.org/info/rfc9727';

    private const API_CATALOG_PATH = '/.well-known/api-catalog';
    private const UCP_PROFILE_PATH = '/.well-known/ucp';

    public function __construct(
        private readonly FallbackAgenticFileRenderer $fileRenderer,
    ) {
    }

    /**
     * @return array{linkset: list<array<string, mixed>>}
     */
    public function build(SalesChannelContext $context): array
    {
        // An unresolvable base URL yields valid root-relative URI references in the linkset.
        $baseUrl = $this->fileRenderer->getSalesChannelBaseUrl($context) ?? '';

        return [
            'linkset' => [
                [
                    'anchor' => $baseUrl.self::API_CATALOG_PATH,
                    // The UCP profile is the shop's machine-readable API description; it is linked
                    // with the same `service-meta` relation the storefront already advertises via
                    // the UCP profile Link header.
                    'service-meta' => [
                        [
                            'href' => $baseUrl.self::UCP_PROFILE_PATH,
                            'type' => 'application/json',
                        ],
                    ],
                    // The Store API entry point is a member API of the catalog (RFC 9727 `item`).
                    'item' => [
                        [
                            'href' => $baseUrl.'/'.DefinitionService::STORE_API,
                            'type' => 'application/json',
                        ],
                    ],
                ],
            ],
        ];
    }
}
