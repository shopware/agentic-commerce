<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\AgenticFiles\ApiCatalog;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\SalesChannelApiTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\AgenticFiles\ApiCatalog\ApiCatalogController;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @internal
 */
final class ApiCatalogControllerTest extends TestCase
{
    use IntegrationTestBehaviour;
    use SalesChannelApiTestBehaviour;

    public function testItServesTheLinksetForAnExposedSalesChannel(): void
    {
        $salesChannelId = Uuid::randomHex();
        $domainId = Uuid::randomHex();
        $this->createSalesChannelWithDomain($salesChannelId, $domainId, 'https://shop.example.com');

        $configService = static::getContainer()->get(UcpConfigService::class);
        static::assertInstanceOf(UcpConfigService::class, $configService);
        $configService->saveConfig(['active' => true], $salesChannelId);

        $response = $this->controller()->apiCatalog($this->salesChannelContext($salesChannelId, $domainId));

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

    public function testItReturns404ForAnUnexposedSalesChannel(): void
    {
        $salesChannelId = Uuid::randomHex();
        $domainId = Uuid::randomHex();
        // No saveConfig(active: true): the channel is not exposed for agentic commerce.
        $this->createSalesChannelWithDomain($salesChannelId, $domainId, 'https://inactive.example.com');

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->apiCatalog($this->salesChannelContext($salesChannelId, $domainId));
    }

    private function createSalesChannelWithDomain(string $salesChannelId, string $domainId, string $url): void
    {
        $this->createSalesChannel([
            'id' => $salesChannelId,
            'domains' => [
                [
                    'id' => $domainId,
                    'languageId' => Defaults::LANGUAGE_SYSTEM,
                    'currencyId' => Defaults::CURRENCY,
                    'snippetSetId' => $this->getSnippetSetIdForLocale('en-GB'),
                    'url' => $url,
                ],
            ],
        ]);
    }

    private function salesChannelContext(string $salesChannelId, string $domainId): SalesChannelContext
    {
        $factory = static::getContainer()->get(SalesChannelContextFactory::class);
        static::assertInstanceOf(SalesChannelContextFactory::class, $factory);

        return $factory->create(
            Uuid::randomHex(),
            $salesChannelId,
            [SalesChannelContextService::DOMAIN_ID => $domainId],
        );
    }

    private function controller(): ApiCatalogController
    {
        $controller = static::getContainer()->get(ApiCatalogController::class);
        static::assertInstanceOf(ApiCatalogController::class, $controller);

        return $controller;
    }
}
