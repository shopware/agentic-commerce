<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Compatibility\Twig;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlRouteConfig;
use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlRouteInterface;
use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlRouteRegistry;
use Shopware\Core\Framework\Adapter\Twig\Extension\SeoUrlFunctionExtension;
use Swag\AgenticCommerce\Compatibility\Twig\EntitySeoUrlCompatExtension;

/**
 * @internal
 *
 * @deprecated tag:v2.0.0 - Covers the {@see EntitySeoUrlCompatExtension} backport (Shopware < 6.7.14); remove
 * together with it once the plugin's minimum supported Shopware version is >= 6.7.14.
 */
#[CoversClass(EntitySeoUrlCompatExtension::class)]
final class EntitySeoUrlCompatExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function entityRouteProvider(): iterable
    {
        yield 'product' => ['product', 'frontend.detail.page', 'productId'];
        yield 'category' => ['category', 'frontend.navigation.page', 'navigationId'];
        yield 'landing page' => ['landing_page', 'frontend.landing.page', 'landingPageId'];
    }

    #[DataProvider('entityRouteProvider')]
    public function testEntitySeoUrlResolvesRouteFromRegistryAndDelegatesToSeoUrl(
        string $entityName,
        string $routeName,
        string $parameterName,
    ): void {
        $primaryKey = '019cdc7c9cab71cbb44933e5a1003492';

        $config = $this->createMock(SeoUrlRouteConfig::class);
        $config->method('getRouteName')->willReturn($routeName);

        $route = $this->createMock(SeoUrlRouteInterface::class);
        $route->method('getConfig')->willReturn($config);

        $registry = $this->createMock(SeoUrlRouteRegistry::class);
        $registry->expects($this->once())
            ->method('findByDefinition')
            ->with($entityName)
            ->willReturn([$route]);

        $seoUrlFunctionExtension = $this->createMock(SeoUrlFunctionExtension::class);
        $seoUrlFunctionExtension->expects($this->once())
            ->method('seoUrl')
            ->with($routeName, [$parameterName => $primaryKey])
            ->willReturn('GENERATED_PLACEHOLDER');

        $extension = new EntitySeoUrlCompatExtension($registry, $seoUrlFunctionExtension, new NullLogger());

        static::assertSame('GENERATED_PLACEHOLDER', $extension->entitySeoUrl($entityName, $primaryKey, $parameterName));
    }

    public function testEntitySeoUrlReturnsEmptyStringAndLogsWhenNoRouteIsRegistered(): void
    {
        $registry = $this->createMock(SeoUrlRouteRegistry::class);
        $registry->method('findByDefinition')->willReturn([]);

        $seoUrlFunctionExtension = $this->createMock(SeoUrlFunctionExtension::class);
        $seoUrlFunctionExtension->expects($this->never())->method('seoUrl');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'No SEO URL route registered for entity "{entityName}"; the product export URL will be empty.',
                ['entityName' => 'unknown_entity'],
            );

        $extension = new EntitySeoUrlCompatExtension($registry, $seoUrlFunctionExtension, $logger);

        static::assertSame('', $extension->entitySeoUrl('unknown_entity', '019cdc7c9cab71cbb44933e5a1003492', 'someId'));
    }

    public function testGetFunctionsRegistersEntitySeoUrl(): void
    {
        // The service is only wired on Shopware < 6.7.14 (the compiler pass removes it otherwise),
        // so the function is registered unconditionally.
        $extension = new EntitySeoUrlCompatExtension(
            $this->createMock(SeoUrlRouteRegistry::class),
            $this->createMock(SeoUrlFunctionExtension::class),
            new NullLogger(),
        );

        $names = array_map(static fn ($function) => $function->getName(), $extension->getFunctions());

        static::assertSame(['entitySeoUrl'], $names);
    }
}
