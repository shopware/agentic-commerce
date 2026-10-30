<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Routing\Router as StorefrontRouter;
use Swag\AgenticCommerce\AgenticFiles\SalesChannelBaseUrlResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutLegalLinkBuilder;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Ucp\Sdk\Model\Common\Link;

/** @internal */
#[CoversClass(CheckoutLegalLinkBuilder::class)]
final class CheckoutLegalLinkBuilderTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0190a1b2c3d4e5f60718293a4b5c6d01';
    private const OTHER_SALES_CHANNEL_ID = '0190a1b2c3d4e5f60718293a4b5c6d02';
    private const TOS_PAGE = '0190a1b2c3d4e5f60718293a4b5c6da1';
    private const PRIVACY_PAGE = '0190a1b2c3d4e5f60718293a4b5c6da2';
    private const REVOCATION_PAGE = '0190a1b2c3d4e5f60718293a4b5c6da3';
    private const SHIPPING_PAGE = '0190a1b2c3d4e5f60718293a4b5c6da4';

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $config = [];

    /**
     * @var list<string>
     */
    private array $existingPageIds = [];

    /**
     * @var list<list<string>>
     */
    private array $searchedPageIds = [];

    /**
     * @var list<array{string, int}>
     */
    private array $generatedRoutes = [];

    protected function setUp(): void
    {
        $this->config = [];
        $this->existingPageIds = [self::TOS_PAGE, self::PRIVACY_PAGE, self::REVOCATION_PAGE, self::SHIPPING_PAGE];
        $this->searchedPageIds = [];
        $this->generatedRoutes = [];
    }

    #[Test]
    public function testAllFourConfiguredPagesBecomeLegalLinksInSpecOrder(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, [
            'core.basicInformation.shippingPaymentInfoPage' => self::SHIPPING_PAGE,
            'core.basicInformation.revocationPage' => self::REVOCATION_PAGE,
            'core.basicInformation.privacyPage' => self::PRIVACY_PAGE,
            'core.basicInformation.tosPage' => self::TOS_PAGE,
        ]);

        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test/'));

        self::assertSame([
            ['type' => 'terms_of_service', 'url' => 'https://shop.test/page/cms/'.self::TOS_PAGE, 'title' => 'Terms of Service'],
            ['type' => 'privacy_policy', 'url' => 'https://shop.test/page/cms/'.self::PRIVACY_PAGE, 'title' => 'Privacy Policy'],
            ['type' => 'refund_policy', 'url' => 'https://shop.test/page/cms/'.self::REVOCATION_PAGE, 'title' => 'Refund Policy'],
            ['type' => 'shipping_policy', 'url' => 'https://shop.test/page/cms/'.self::SHIPPING_PAGE, 'title' => 'Shipping Policy'],
        ], $this->toArrays($links));

        $types = array_map(static fn (Link $link): string => $link->type, $links);
        self::assertSame($types, array_values(array_unique($types)));
        foreach ($links as $link) {
            self::assertNotFalse(filter_var($link->url, \FILTER_VALIDATE_URL));
            self::assertStringStartsWith('https://', $link->url);
        }
        self::assertCount(1, $this->searchedPageIds, 'All configured pages are checked with a single query.');
        self::assertSame([StorefrontRouter::PATH_INFO], array_values(array_unique(array_column($this->generatedRoutes, 1))), 'Paths are generated without the domain prefix, which the base URL already carries.');
    }

    #[Test]
    public function testOnlyConfiguredPagesBecomeLinks(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, [
            'core.basicInformation.tosPage' => self::TOS_PAGE,
            'core.basicInformation.revocationPage' => self::REVOCATION_PAGE,
        ]);

        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));

        self::assertSame(['terms_of_service', 'refund_policy'], array_map(static fn (Link $link): string => $link->type, $links));
    }

    #[Test]
    public function testNoConfiguredPageYieldsNoLinksAndNoQuery(): void
    {
        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));

        self::assertSame([], $links);
        self::assertSame([], $this->searchedPageIds);
        self::assertSame([], $this->generatedRoutes, 'The router must not be asked when no page is configured.');
    }

    #[Test]
    public function testNonStringConfigValuesAreSkipped(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, [
            'core.basicInformation.tosPage' => 42,
            'core.basicInformation.privacyPage' => [self::PRIVACY_PAGE],
            'core.basicInformation.revocationPage' => true,
            'core.basicInformation.shippingPaymentInfoPage' => self::SHIPPING_PAGE,
        ]);

        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));

        self::assertSame(['shipping_policy'], array_map(static fn (Link $link): string => $link->type, $links));
        self::assertSame([[self::SHIPPING_PAGE]], $this->searchedPageIds);
    }

    #[Test]
    public function testStringConfigValuesThatAreNotUuidsAreSkipped(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, [
            'core.basicInformation.tosPage' => '',
            'core.basicInformation.privacyPage' => 'privacy-policy',
            'core.basicInformation.revocationPage' => self::REVOCATION_PAGE.'0',
            'core.basicInformation.shippingPaymentInfoPage' => self::SHIPPING_PAGE,
        ]);

        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));

        self::assertSame(['shipping_policy'], array_map(static fn (Link $link): string => $link->type, $links));
        self::assertSame([[self::SHIPPING_PAGE]], $this->searchedPageIds);
    }

    #[Test]
    public function testOnlyUnusableConfigValuesYieldNoLinksAndNoQuery(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, [
            'core.basicInformation.tosPage' => 'terms',
            'core.basicInformation.privacyPage' => 1.5,
        ]);

        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));

        self::assertSame([], $links);
        self::assertSame([], $this->searchedPageIds);
        self::assertSame([], $this->generatedRoutes);
    }

    #[Test]
    public function testAConfiguredPageThatNoLongerExistsIsDropped(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, [
            'core.basicInformation.tosPage' => self::TOS_PAGE,
            'core.basicInformation.privacyPage' => self::PRIVACY_PAGE,
        ]);
        $this->existingPageIds = [self::PRIVACY_PAGE];

        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));

        self::assertSame(['privacy_policy'], array_map(static fn (Link $link): string => $link->type, $links));
    }

    #[Test]
    public function testTheSamePageConfiguredTwiceIsQueriedOnceAndLinkedForEachType(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, [
            'core.basicInformation.tosPage' => self::TOS_PAGE,
            'core.basicInformation.revocationPage' => self::TOS_PAGE,
        ]);

        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));

        self::assertSame(['terms_of_service', 'refund_policy'], array_map(static fn (Link $link): string => $link->type, $links));
        self::assertSame([[self::TOS_PAGE]], $this->searchedPageIds);
    }

    #[Test]
    public function testNoBaseUrlYieldsNoLinks(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, ['core.basicInformation.tosPage' => self::TOS_PAGE]);

        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, null));

        self::assertSame([], $links);
        self::assertSame([], $this->searchedPageIds);
    }

    #[Test]
    public function testTheFullCmsPageRouteIsPreferredOverTheWidgetRoute(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, ['core.basicInformation.tosPage' => self::TOS_PAGE]);

        $links = $this->builder(fullRoute: true, widgetRoute: true)->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));

        self::assertSame('https://shop.test/page/cms/'.self::TOS_PAGE, $links[0]->url);
        self::assertNotContains('frontend.cms.page', array_column($this->generatedRoutes, 0));
    }

    #[Test]
    public function testTheWidgetRouteIsUsedWhenTheFullCmsPageRouteIsMissing(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, ['core.basicInformation.tosPage' => self::TOS_PAGE]);

        $links = $this->builder(fullRoute: false, widgetRoute: true)->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));

        self::assertSame('https://shop.test/widgets/cms/'.self::TOS_PAGE, $links[0]->url);
    }

    #[Test]
    public function testNoCmsRouteYieldsNoLinksAndNoQuery(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, ['core.basicInformation.tosPage' => self::TOS_PAGE]);

        $links = $this->builder(fullRoute: false, widgetRoute: false)->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));

        self::assertSame([], $links);
        self::assertSame([], $this->searchedPageIds);
    }

    #[Test]
    public function testAPathPrefixedDomainKeepsItsPrefix(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, ['core.basicInformation.tosPage' => self::TOS_PAGE]);

        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test/de/'));

        self::assertSame('https://shop.test/de/page/cms/'.self::TOS_PAGE, $links[0]->url);
    }

    #[Test]
    public function testEachSalesChannelGetsItsOwnPagesAndDomain(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, ['core.basicInformation.tosPage' => self::TOS_PAGE]);
        $this->configure(self::OTHER_SALES_CHANNEL_ID, ['core.basicInformation.privacyPage' => self::PRIVACY_PAGE]);
        $builder = $this->builder();

        $links = $builder->build($this->context(self::SALES_CHANNEL_ID, 'https://shop.test'));
        $otherLinks = $builder->build($this->context(self::OTHER_SALES_CHANNEL_ID, 'https://other.test'));

        self::assertSame([
            ['type' => 'terms_of_service', 'url' => 'https://shop.test/page/cms/'.self::TOS_PAGE, 'title' => 'Terms of Service'],
        ], $this->toArrays($links));
        self::assertSame([
            ['type' => 'privacy_policy', 'url' => 'https://other.test/page/cms/'.self::PRIVACY_PAGE, 'title' => 'Privacy Policy'],
        ], $this->toArrays($otherLinks));
    }

    #[Test]
    public function testAUrlThatDoesNotValidateIsDropped(): void
    {
        $this->configure(self::SALES_CHANNEL_ID, ['core.basicInformation.tosPage' => self::TOS_PAGE]);

        $links = $this->builder()->build($this->context(self::SALES_CHANNEL_ID, 'https://shöp.test'));

        self::assertSame([], $links);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function configure(string $salesChannelId, array $values): void
    {
        $this->config[$salesChannelId] = $values;
    }

    private function builder(bool $fullRoute = true, bool $widgetRoute = true): CheckoutLegalLinkBuilder
    {
        $paths = array_filter([
            'frontend.cms.page.full' => $fullRoute ? '/page/cms/' : null,
            'frontend.cms.page' => $widgetRoute ? '/widgets/cms/' : null,
        ]);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            function (string $name, array $parameters = [], int $referenceType = RouterInterface::ABSOLUTE_PATH) use ($paths): string {
                $this->generatedRoutes[] = [$name, $referenceType];
                if (!isset($paths[$name])) {
                    throw new RouteNotFoundException(\sprintf('Route "%s" does not exist.', $name));
                }

                return $paths[$name].$parameters['id'];
            },
        );

        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturnCallback(
            fn (string $key, ?string $salesChannelId = null): mixed => $this->config[$salesChannelId ?? ''][$key] ?? null,
        );

        $domainRepository = $this->createMock(EntityRepository::class);
        $domainRepository->expects(static::never())->method('search');

        $cmsPageRepository = $this->createMock(EntityRepository::class);
        $cmsPageRepository->method('searchIds')->willReturnCallback(function (Criteria $criteria, Context $context): IdSearchResult {
            /** @var list<string> $ids */
            $ids = $criteria->getIds();
            $this->searchedPageIds[] = $ids;
            $found = [];
            foreach (array_intersect($ids, $this->existingPageIds) as $id) {
                $found[$id] = ['primaryKey' => $id, 'data' => ['id' => $id]];
            }

            return new IdSearchResult(\count($found), $found, $criteria, $context);
        });

        return new CheckoutLegalLinkBuilder(
            $router,
            $systemConfigService,
            new SalesChannelBaseUrlResolver($domainRepository),
            $cmsPageRepository,
        );
    }

    private function context(string $salesChannelId, ?string $domainUrl): SalesChannelContext
    {
        $domainId = md5($salesChannelId);
        $domains = new SalesChannelDomainCollection();
        if (null !== $domainUrl) {
            $domain = new SalesChannelDomainEntity();
            $domain->setId($domainId);
            $domain->setSalesChannelId($salesChannelId);
            $domain->setUrl($domainUrl);
            $domains->add($domain);
        }

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId($salesChannelId);
        $salesChannel->setDomains($domains);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);
        $context->method('getSalesChannel')->willReturn($salesChannel);
        $context->method('getDomainId')->willReturn(null !== $domainUrl ? $domainId : null);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }

    /**
     * @param list<Link> $links
     *
     * @return list<array<string, string>>
     */
    private function toArrays(array $links): array
    {
        return array_map(static fn (Link $link): array => $link->toArray(), $links);
    }
}
