<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Discovery\ApiCatalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Discovery\ApiCatalog\ApiCatalogController;
use Swag\AgenticCommerce\Discovery\ApiCatalog\ApiCatalogLinksetBuilder;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Package('discovery')]
#[CoversClass(ApiCatalogController::class)]
final class ApiCatalogControllerTest extends TestCase
{
    #[Test]
    public function itServesTheLinksetForAnExposedSalesChannel(): void
    {
        $response = $this->controller(new UcpConfig(active: true))
            ->apiCatalog(Request::create('https://shop.example/.well-known/api-catalog'), $this->salesChannelContext());

        static::assertSame(200, $response->getStatusCode());
        static::assertSame(
            'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"',
            $response->headers->get('content-type'),
        );

        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        static::assertIsArray($payload);
        static::assertArrayHasKey('linkset', $payload);
        static::assertSame('https://shop.example/.well-known/api-catalog', $payload['linkset'][0]['anchor']);
    }

    #[Test]
    public function itHidesUnescapedSlashesSoHrefsStayReadable(): void
    {
        $response = $this->controller(new UcpConfig(active: true))
            ->apiCatalog(Request::create('https://shop.example/.well-known/api-catalog'), $this->salesChannelContext());

        static::assertStringContainsString('https://shop.example/.well-known/ucp', (string) $response->getContent());
    }

    #[Test]
    public function itReturnsNotFoundForAnUnexposedSalesChannel(): void
    {
        $controller = $this->controller(new UcpConfig(active: false));

        $this->expectException(NotFoundHttpException::class);

        $controller->apiCatalog(Request::create('https://shop.example/.well-known/api-catalog'), $this->salesChannelContext());
    }

    private function controller(UcpConfig $config): ApiCatalogController
    {
        $configRepository = $this->createStub(UcpConfigRepositoryInterface::class);
        $configRepository->method('find')->willReturn($config);

        return new ApiCatalogController(
            new UcpConfigService($configRepository, $this->createStub(LegacyConfigStoreInterface::class)),
            new ApiCatalogLinksetBuilder(new ShopwareVersionDetector('6.7.0.0')),
        );
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');

        return $context;
    }
}
