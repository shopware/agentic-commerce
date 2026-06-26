<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Fallback;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID], 'auth_required' => false])]
#[Package('discovery')]
final class FallbackAgenticFileController
{
    public function __construct(
        private readonly UcpConfigService $configService,
        private readonly FallbackAgenticFileRenderer $renderer,
    ) {
    }

    #[Route(path: '/llms.txt', name: 'swag_agentic_commerce.llms_txt', methods: ['GET'])]
    public function llms(SalesChannelContext $context): Response
    {
        return $this->render('llms.txt', $context);
    }

    #[Route(path: '/agents.md', name: 'swag_agentic_commerce.agents_md', methods: ['GET'])]
    public function agents(SalesChannelContext $context): Response
    {
        return $this->render('agents.md', $context);
    }

    #[Route(path: '/.well-known/ai-catalog.json', name: 'swag_agentic_commerce.ai_catalog', methods: ['GET'])]
    public function aiCatalog(SalesChannelContext $context): Response
    {
        return $this->render('.well-known/ai-catalog.json', $context);
    }

    private function render(string $fileName, SalesChannelContext $context): Response
    {
        if (!$this->configService->getConfig($context->getSalesChannelId())->active) {
            throw new NotFoundHttpException();
        }

        return new Response(
            $this->renderer->render($fileName, $context),
            Response::HTTP_OK,
            ['content-type' => $this->renderer->contentType($fileName)],
        );
    }
}
