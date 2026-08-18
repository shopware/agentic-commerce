<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\ApiCatalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\AgenticFiles\ApiCatalog\ApiCatalogController;
use Swag\AgenticCommerce\AgenticFiles\ApiCatalog\ApiCatalogLinksetBuilder;
use Swag\AgenticCommerce\AgenticFiles\SalesChannelBaseUrlResolver;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @internal
 */
#[CoversClass(ApiCatalogController::class)]
final class ApiCatalogControllerTest extends TestCase
{
    public function testItServesALinksetWithApiCatalogLinkHeaderForExposedSalesChannels(): void
    {
        $salesChannelId = Uuid::randomHex();

        $controller = new ApiCatalogController(
            $this->configService(new UcpConfig(active: true)),
            new ApiCatalogLinksetBuilder(),
            $this->baseUrlResolver($salesChannelId, 'https://shop.example.com/'),
        );

        $response = $controller->apiCatalog($this->context($salesChannelId));

        static::assertSame(Response::HTTP_OK, $response->getStatusCode());
        static::assertSame(
            'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"',
            $response->headers->get('Content-Type'),
        );
        static::assertSame(
            '<https://shop.example.com/.well-known/api-catalog>; rel="api-catalog"',
            $response->headers->get('Link'),
        );

        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame([
            'linkset' => [
                [
                    'anchor' => 'https://shop.example.com/.well-known/api-catalog',
                    'service-meta' => [
                        ['href' => 'https://shop.example.com/.well-known/ucp', 'type' => 'application/json'],
                    ],
                    'item' => [
                        ['href' => 'https://shop.example.com/store-api', 'type' => 'application/json'],
                    ],
                ],
            ],
        ], $payload);
    }

    public function testItKeepsSlashesUnescapedInTheBody(): void
    {
        $salesChannelId = Uuid::randomHex();

        $controller = new ApiCatalogController(
            $this->configService(new UcpConfig(active: true)),
            new ApiCatalogLinksetBuilder(),
            $this->baseUrlResolver($salesChannelId, 'https://shop.example.com/'),
        );

        $body = (string) $controller->apiCatalog($this->context($salesChannelId))->getContent();

        static::assertStringContainsString('https://shop.example.com/.well-known/ucp', $body);
        static::assertStringNotContainsString('\\/', $body);
    }

    public function testItReturns404ForUnexposedSalesChannels(): void
    {
        $salesChannelId = Uuid::randomHex();

        $controller = new ApiCatalogController(
            $this->configService(new UcpConfig(active: false)),
            new ApiCatalogLinksetBuilder(),
            $this->baseUrlResolver($salesChannelId, 'https://shop.example.com/'),
        );

        $this->expectException(NotFoundHttpException::class);

        $controller->apiCatalog($this->context($salesChannelId));
    }

    private function configService(UcpConfig $config): UcpConfigService
    {
        $repository = new class($config) implements UcpConfigRepositoryInterface {
            public function __construct(private UcpConfig $config)
            {
            }

            public function find(string $salesChannelId): UcpConfig
            {
                return $this->config;
            }

            public function findMany(array $salesChannelIds): array
            {
                return [];
            }

            public function save(string $salesChannelId, UcpConfig $config): void
            {
            }
        };

        return new UcpConfigService($repository, $this->createMock(LegacyConfigStoreInterface::class));
    }

    private function baseUrlResolver(string $salesChannelId, string $domainUrl): SalesChannelBaseUrlResolver
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId(Uuid::randomHex());
        $domain->setSalesChannelId($salesChannelId);
        $domain->setLanguageId(Uuid::randomHex());
        $domain->setUrl($domainUrl);

        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId($salesChannelId);
        $salesChannel->setDomains(new SalesChannelDomainCollection([$domain]));

        $salesChannels = new SalesChannelCollection([$salesChannel]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel',
                $salesChannels->count(),
                $salesChannels,
                null,
                $criteria,
                $context,
            ),
        );

        return new SalesChannelBaseUrlResolver($repository);
    }

    private function context(string $salesChannelId): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);
        $context->method('getDomainId')->willReturn(null);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        return $context;
    }
}
