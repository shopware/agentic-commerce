<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\DependencyInjection\UcpMcpToolRegistration;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCompletionPayment;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpCheckoutCompletionPreview;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpMcpToolContext;
use Swag\AgenticCommerce\Ucp\Mcp\UcpMcpToolset;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * The UCP tools return core's `McpToolResult`, which only exists from 6.7.16.0. Before that they
 * must not be registered at all, neither by the explicit registration nor by the service glob.
 *
 * @internal
 */
#[CoversClass(UcpMcpToolRegistration::class)]
#[CoversClass(UcpMcpToolset::class)]
final class UcpMcpToolRegistrationTest extends TestCase
{
    private const TOOL_NAMESPACE = 'Swag\\AgenticCommerce\\Ucp\\Mcp\\Tool\\';

    #[Test]
    public function testRegistersEveryToolAsAStoreApiToolWhereCoreSupportsToolResults(): void
    {
        $container = $this->registerTools(true);

        foreach (UcpMcpToolRegistration::TOOLS as $tool) {
            self::assertTrue($container->hasDefinition($tool), \sprintf('%s is not registered.', $tool));
            self::assertTrue(
                $container->getDefinition($tool)->hasTag(UcpMcpToolRegistration::STORE_API_TOOL_TAG),
                \sprintf('%s is not tagged as a Store API MCP tool.', $tool),
            );
        }
    }

    #[Test]
    public function testRegistersNoToolWhereCoreLacksToolResults(): void
    {
        $container = $this->registerTools(false);

        foreach (UcpMcpToolRegistration::TOOLS as $tool) {
            self::assertFalse($container->hasDefinition($tool), \sprintf('%s is registered on a core without McpToolResult.', $tool));
        }
    }

    #[Test]
    public function testTheToolListCoversEveryToolClass(): void
    {
        $files = glob(__DIR__.'/../../src/Ucp/Mcp/Tool/*Tool.php');
        self::assertNotFalse($files);

        $found = array_map(static fn (string $file): string => self::TOOL_NAMESPACE.basename($file, '.php'), $files);
        $listed = UcpMcpToolRegistration::TOOLS;

        sort($found);
        sort($listed);

        // A tool missing from the list would never be registered, as the glob excludes it.
        self::assertSame($found, $listed);
    }

    #[Test]
    public function testServicesRegisterTheToolsExactlyWhenCoreSupportsToolResults(): void
    {
        $supported = UcpMcpToolset::coreSupportsToolResults();
        $container = $this->loadServices();

        foreach (UcpMcpToolRegistration::TOOLS as $tool) {
            if ($supported) {
                self::assertTrue($container->hasDefinition($tool), \sprintf('%s is not registered.', $tool));
                self::assertTrue($container->getDefinition($tool)->hasTag(UcpMcpToolRegistration::STORE_API_TOOL_TAG));

                continue;
            }

            // Autoconfiguration tags any `#[McpTool]` class it loads, so a tool reaching the
            // container through the glob would be served by core's Admin API server.
            self::assertFalse($container->hasDefinition($tool), \sprintf('%s reached the container without core support.', $tool));
        }

        // The helpers the tools share live in the same directory and stay registered either way.
        foreach ([UcpMcpToolContext::class, UcpCheckoutCompletionPreview::class, UcpCheckoutCompletionPayment::class] as $helper) {
            self::assertTrue($container->hasDefinition($helper), \sprintf('%s is not registered.', $helper));
        }
    }

    #[Test]
    public function testCoreSupportsToolResultsOnlyWithBothResultClasses(): void
    {
        self::assertSame(
            class_exists('Shopware\\Core\\Framework\\Mcp\\Result\\McpToolResult')
                && class_exists('Shopware\\Core\\Framework\\Mcp\\Result\\McpToolResultRenderer'),
            UcpMcpToolset::coreSupportsToolResults(),
        );
    }

    private function registerTools(bool $coreSupportsToolResults): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $instanceof = [];
        $configurator = new ContainerConfigurator(
            $container,
            new PhpFileLoader($container, new FileLocator()),
            $instanceof,
            __FILE__,
            __FILE__,
        );

        UcpMcpToolRegistration::register($configurator->services(), $coreSupportsToolResults);

        return $container;
    }

    private function loadServices(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // The unit-test harness does not boot the UCP SDK bundle extension.
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'ucp_sdk';
            }
        });

        (new PhpFileLoader($container, new FileLocator()))->load(__DIR__.'/../../src/Resources/config/services.php');

        return $container;
    }
}
