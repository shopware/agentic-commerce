<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Fallback;

use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

#[Package('discovery')]
final readonly class FallbackAgenticFileRenderer
{
    private const FILE_FAMILY = 'agentic';

    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private Environment $twig,
        private TemplateFinder $templateFinder,
        private SeoUrlPlaceholderHandlerInterface $seoUrlPlaceholderHandler,
        private EntityRepository $salesChannelRepository,
        private RouterInterface $router,
    ) {
    }

    public function render(string $fileName, SalesChannelContext $context): string
    {
        $file = $this->createFile($fileName);
        $salesChannel = $this->loadSalesChannel($context);
        $templateName = $this->templateFinder->find($file->templatePath);

        $content = $this->twig->render($templateName, [
            'context' => $context,
            'salesChannel' => $salesChannel,
            'salesChannelFile' => $file,
            'cmsPageRouteName' => $this->cmsPageRouteName(),
        ]);

        return $this->seoUrlPlaceholderHandler->replace($content, '', $context);
    }

    public function contentType(string $fileName): string
    {
        return match ($fileName) {
            'agents.md' => 'text/markdown; charset=utf-8',
            default => 'text/plain; charset=utf-8',
        };
    }

    private function createFile(string $fileName): FallbackSalesChannelFile
    {
        $templatePath = 'files/'.self::FILE_FAMILY.'/'.$fileName.'.twig';

        return new FallbackSalesChannelFile(
            self::FILE_FAMILY,
            $fileName,
            $templatePath,
            $this->contentType($fileName),
            $templatePath,
        );
    }

    private function cmsPageRouteName(): string
    {
        if (null !== $this->router->getRouteCollection()->get('frontend.cms.page.full')) {
            return 'frontend.cms.page.full';
        }

        return 'frontend.cms.page';
    }

    private function loadSalesChannel(SalesChannelContext $context): SalesChannelEntity
    {
        $criteria = (new Criteria([$context->getSalesChannelId()]))
            ->addAssociation('languages.translationCode')
            ->addAssociation('languages.locale')
            ->addAssociation('currencies')
            ->setLimit(1);

        $salesChannel = $this->salesChannelRepository
            ->search($criteria, $context->getContext())
            ->first();

        return $salesChannel instanceof SalesChannelEntity ? $salesChannel : $context->getSalesChannel();
    }
}
