<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Discovery\ApiCatalog;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the RFC 9727 API catalog.
 *
 * Deliberately not part of the `agentic` sales-channel file family: RFC 9727 fixes
 * the path at /.well-known/api-catalog without a file extension, which core's
 * SalesChannelFileRequestPathResolver rejects outright, and core derives the file
 * content type from that missing extension. A plain route therefore serves every
 * supported lane through one code path.
 *
 * The exposure gate is the live UCP config on all lanes. That is stricter than the
 * 6.7+ file family, where CoreSalesChannelFileBridge enables sales_channel_file rows
 * on activation but never disables them again.
 *
 * @internal
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID], 'auth_required' => false])]
#[Package('discovery')]
final class ApiCatalogController
{
    private const CONTENT_TYPE = 'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"';

    public function __construct(
        private readonly UcpConfigService $configService,
        private readonly ApiCatalogLinksetBuilder $linksetBuilder,
    ) {
    }

    #[Route(path: '/.well-known/api-catalog', name: 'swag_agentic_commerce.api_catalog', methods: ['GET'])]
    public function apiCatalog(Request $request, SalesChannelContext $context): Response
    {
        $config = $this->configService->getConfig($context->getSalesChannelId());
        if (!$config->active) {
            throw new NotFoundHttpException();
        }

        $linkset = $this->linksetBuilder->build($config, $request->getSchemeAndHttpHost());

        return new Response(
            json_encode($linkset, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT),
            Response::HTTP_OK,
            ['content-type' => self::CONTENT_TYPE],
        );
    }
}
