<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Content\Cms\CmsPageCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Routing\Router as StorefrontRouter;
use Swag\AgenticCommerce\AgenticFiles\SalesChannelBaseUrlResolver;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Ucp\Sdk\Model\Common\Link;

/**
 * Builds the UCP legal links from the CMS pages configured under Settings > Basic information.
 * Uses the full CMS page route (6.6.10+) when available, otherwise the CMS widget route, generated
 * as bare path info so path-prefixed domains keep a single prefix. Without a storefront there are no links.
 *
 * @internal
 */
#[Package('checkout')]
final class CheckoutLegalLinkBuilder
{
    private const LEGAL_PAGES = [
        'terms_of_service' => ['core.basicInformation.tosPage', 'Terms of Service'],
        'privacy_policy' => ['core.basicInformation.privacyPage', 'Privacy Policy'],
        'refund_policy' => ['core.basicInformation.revocationPage', 'Refund Policy'],
        'shipping_policy' => ['core.basicInformation.shippingPaymentInfoPage', 'Shipping Policy'],
    ];

    /**
     * @param EntityRepository<CmsPageCollection> $cmsPageRepository
     */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly SystemConfigService $systemConfigService,
        private readonly SalesChannelBaseUrlResolver $baseUrlResolver,
        private readonly EntityRepository $cmsPageRepository,
    ) {
    }

    /**
     * @return list<Link>
     */
    public function build(SalesChannelContext $context): array
    {
        if (!class_exists(StorefrontRouter::class)) {
            return [];
        }

        $pageIds = $this->configuredPageIds($context->getSalesChannelId());
        if ([] === $pageIds) {
            return [];
        }

        $routeName = $this->cmsPageRouteName(reset($pageIds));
        if (null === $routeName) {
            return [];
        }

        $baseUrl = $this->baseUrlResolver->resolve($context);
        if (null === $baseUrl) {
            return [];
        }

        $existingIds = $this->cmsPageRepository
            ->searchIds(new Criteria(array_values(array_unique($pageIds))), $context->getContext())
            ->getIds();

        $links = [];
        foreach ($pageIds as $type => $pageId) {
            if (!\in_array($pageId, $existingIds, true)) {
                continue;
            }

            $url = $baseUrl.$this->router->generate($routeName, ['id' => $pageId], StorefrontRouter::PATH_INFO);
            if (false === filter_var($url, \FILTER_VALIDATE_URL)) {
                continue;
            }

            $links[] = new Link($type, $url, self::LEGAL_PAGES[$type][1]);
        }

        return $links;
    }

    private function cmsPageRouteName(string $pageId): ?string
    {
        foreach (['frontend.cms.page.full', 'frontend.cms.page'] as $routeName) {
            try {
                $this->router->generate($routeName, ['id' => $pageId], StorefrontRouter::PATH_INFO);

                return $routeName;
            } catch (RouteNotFoundException) {
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function configuredPageIds(string $salesChannelId): array
    {
        $pageIds = [];
        foreach (self::LEGAL_PAGES as $type => [$configKey]) {
            $pageId = $this->systemConfigService->get($configKey, $salesChannelId);
            if (\is_string($pageId) && Uuid::isValid($pageId)) {
                $pageIds[$type] = $pageId;
            }
        }

        return $pageIds;
    }
}
