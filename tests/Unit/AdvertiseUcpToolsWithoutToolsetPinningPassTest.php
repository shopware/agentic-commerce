<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\DependencyInjection\AdvertiseUcpToolsWithoutToolsetPinningPass;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCartGetTool;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCatalogSearchTool;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/** @internal */
#[CoversClass(AdvertiseUcpToolsWithoutToolsetPinningPass::class)]
final class AdvertiseUcpToolsWithoutToolsetPinningPassTest extends TestCase
{
    public function testAddsTheUcpToolsWhenCoreCannotPinToolsets(): void
    {
        $container = $this->container(['shopware-tool-search']);

        (new AdvertiseUcpToolsWithoutToolsetPinningPass(coreSupportsConnectTimeToolsets: false))->process($container);

        self::assertSame(
            ['shopware-tool-search', 'get_cart', 'search_catalog'],
            $container->getParameter(AdvertiseUcpToolsWithoutToolsetPinningPass::ADVERTISED_TOOLS_PARAMETER),
        );
    }

    public function testLeavesTheDefaultSurfaceAloneWhenCoreCanPinToolsets(): void
    {
        $container = $this->container(['shopware-tool-search']);

        (new AdvertiseUcpToolsWithoutToolsetPinningPass(coreSupportsConnectTimeToolsets: true))->process($container);

        self::assertSame(['shopware-tool-search'], $container->getParameter(AdvertiseUcpToolsWithoutToolsetPinningPass::ADVERTISED_TOOLS_PARAMETER));
    }

    public function testIgnoresToolsOutsideTheUcpToolsetAndUnknownClasses(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(AdvertiseUcpToolsWithoutToolsetPinningPass::ADVERTISED_TOOLS_PARAMETER, []);
        $container->setDefinition('other.tool', (new Definition(\stdClass::class))->addTag('shopware.store_api_mcp.tool'));
        $container->setDefinition('missing.tool', (new Definition('Missing\\ToolClass'))->addTag('shopware.store_api_mcp.tool'));

        (new AdvertiseUcpToolsWithoutToolsetPinningPass(coreSupportsConnectTimeToolsets: false))->process($container);

        self::assertSame([], $container->getParameter(AdvertiseUcpToolsWithoutToolsetPinningPass::ADVERTISED_TOOLS_PARAMETER));
    }

    public function testDoesNothingWithoutTheStoreApiMcpServer(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(UcpCartGetTool::class, (new Definition(UcpCartGetTool::class))->addTag('shopware.store_api_mcp.tool'));

        (new AdvertiseUcpToolsWithoutToolsetPinningPass(coreSupportsConnectTimeToolsets: false))->process($container);

        self::assertFalse($container->hasParameter(AdvertiseUcpToolsWithoutToolsetPinningPass::ADVERTISED_TOOLS_PARAMETER));
    }

    /**
     * @param list<string> $advertised
     */
    private function container(array $advertised): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(AdvertiseUcpToolsWithoutToolsetPinningPass::ADVERTISED_TOOLS_PARAMETER, $advertised);
        $container->setDefinition(UcpCartGetTool::class, (new Definition(UcpCartGetTool::class))->addTag('shopware.store_api_mcp.tool'));
        $container->setDefinition(UcpCatalogSearchTool::class, (new Definition(UcpCatalogSearchTool::class))->addTag('shopware.store_api_mcp.tool'));
        // Listed twice must not advertise twice.
        $container->setDefinition('alias.cart.get', (new Definition(UcpCartGetTool::class))->addTag('shopware.store_api_mcp.tool'));

        return $container;
    }
}
