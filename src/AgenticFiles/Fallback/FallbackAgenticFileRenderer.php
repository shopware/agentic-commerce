<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Fallback;

use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

/** @internal */
#[Package('discovery')]
final class FallbackAgenticFileRenderer
{
    private const FILE_FAMILY = 'agentic';

    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly Environment $twig,
        private readonly TemplateFinder $templateFinder,
        private readonly SeoUrlPlaceholderHandlerInterface $seoUrlPlaceholderHandler,
        private readonly EntityRepository $salesChannelRepository,
        private readonly RouterInterface $router,
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
            'salesChannelFileContext' => $this->buildSalesChannelFileContext($salesChannel, $context),
            'cmsPageRouteName' => $this->cmsPageRouteName(),
        ]);

        return $this->seoUrlPlaceholderHandler->replace($content, '', $context);
    }

    public function contentType(string $fileName): string
    {
        return match ($fileName) {
            '.well-known/ai-catalog.json' => 'application/json; charset=utf-8',
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
            ->addAssociation('domains')
            ->setLimit(1);

        $salesChannel = $this->salesChannelRepository
            ->search($criteria, $context->getContext())
            ->first();

        return $salesChannel instanceof SalesChannelEntity ? $salesChannel : $context->getSalesChannel();
    }

    /**
     * @return array{baseUrl: string|null, publisher: string|null}
     */
    private function buildSalesChannelFileContext(SalesChannelEntity $salesChannel, SalesChannelContext $context): array
    {
        $baseUrl = $this->resolveBaseUrl($salesChannel, $context);

        return [
            'baseUrl' => $baseUrl,
            'publisher' => null === $baseUrl ? null : $this->extractPublisher($baseUrl),
        ];
    }

    private function resolveBaseUrl(SalesChannelEntity $salesChannel, SalesChannelContext $context): ?string
    {
        $domains = $salesChannel->getDomains();
        if (null === $domains || 0 === $domains->count()) {
            return null;
        }

        $domainId = $context->getDomainId();
        if (null !== $domainId) {
            $domain = $domains->get($domainId);
            if ($domain instanceof SalesChannelDomainEntity) {
                return rtrim($domain->getUrl(), '/');
            }
        }

        $domain = $domains->first();

        return $domain instanceof SalesChannelDomainEntity ? rtrim($domain->getUrl(), '/') : null;
    }

    private function extractPublisher(string $baseUrl): ?string
    {
        $host = parse_url($baseUrl, \PHP_URL_HOST);

        return \is_string($host) && '' !== $host ? strtolower($host) : null;
    }
}
