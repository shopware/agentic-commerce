<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\ApiCatalog;

use Shopware\Core\Framework\Api\ApiDefinition\DefinitionService;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Profile\UcpProfileLocator;

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
     * Profile URI advertised on the linkset per RFC 9727 §4.2 (a SHOULD).
     */
    public const PROFILE_URI = 'https://www.rfc-editor.org/info/rfc9727';

    public const API_CATALOG_PATH = '/.well-known/api-catalog';

    public function __construct(
        private readonly UcpProfileLocator $profileLocator,
    ) {
    }

    /**
     * @return array{linkset: list<array<string, mixed>>}
     */
    public function build(string $baseUrl): array
    {
        // An empty base URL yields valid root-relative URI references in the linkset.
        $apiCatalogAnchorEntry = ['anchor' => $baseUrl.self::API_CATALOG_PATH];

        $ucpProfileUrl = $this->profileLocator->profileUrl($baseUrl);
        if (null !== $ucpProfileUrl) {
            // The UCP profile is the shop's machine-readable API description; it is linked
            // with the same `service-meta` relation the storefront already advertises via
            // the UCP profile Link header.
            $apiCatalogAnchorEntry['service-meta'] = [
                [
                    'href' => $ucpProfileUrl,
                    'type' => 'application/json',
                ],
            ];
        }

        // The Store API entry point is a member API of the catalog (RFC 9727 `item`).
        $apiCatalogAnchorEntry['item'] = [
            [
                'href' => $baseUrl.'/'.DefinitionService::STORE_API,
                'type' => 'application/json',
            ],
        ];

        return ['linkset' => [$apiCatalogAnchorEntry]];
    }
}
