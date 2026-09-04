<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\ApiCatalog;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\AgenticFiles\SalesChannelBaseUrlResolver;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID], 'auth_required' => false])]
/** @internal */
#[Package('discovery')]
final class ApiCatalogController
{
    // RFC 9727 mandates the RFC 9264 `application/linkset+json` media type with a `profile`
    // parameter. This is a dedicated controller precisely because the core sales-channel-file
    // mechanism derives the content type from the file extension (Symfony MimeTypes): the
    // extensionless `/.well-known/api-catalog` would fall back to `text/plain`, and even a
    // `.json`-suffixed path could only ever yield `application/json` — never the linkset type or
    // its profile parameter. So it is set explicitly here.
    private const CONTENT_TYPE = 'application/linkset+json; profile="'.ApiCatalogLinksetBuilder::PROFILE_URI.'"';

    public function __construct(
        private readonly UcpConfigService $configService,
        private readonly ApiCatalogLinksetBuilder $linksetBuilder,
        private readonly SalesChannelBaseUrlResolver $baseUrlResolver,
    ) {
    }

    #[Route(path: '/.well-known/api-catalog', name: 'swag_agentic_commerce.api_catalog', methods: ['GET'])]
    public function apiCatalog(SalesChannelContext $context): Response
    {
        // Unexposed sales channels must 404, matching the other agentic well-known routes.
        if (!$this->configService->getConfig($context->getSalesChannelId())->active) {
            throw new NotFoundHttpException();
        }

        // An empty base URL yields valid root-relative references in both the body and the header.
        $baseUrl = $this->baseUrlResolver->resolve($context) ?? '';
        $catalogUrl = $baseUrl.ApiCatalogLinksetBuilder::API_CATALOG_PATH;

        $body = json_encode($this->linksetBuilder->build($baseUrl), \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return new Response($body, Response::HTTP_OK, [
            'content-type' => self::CONTENT_TYPE,
            // RFC 9727 §2 requires the HEAD (and, harmlessly, GET) response to carry a Link header
            // with the `api-catalog` relation. HEAD resolves through this GET handler.
            'link' => '<'.$catalogUrl.'>; rel="api-catalog"',
        ]);
    }
}
