<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Composer\InstalledVersions;
use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\UcpProtocol;

/**
 * Every UCP MCP tool must carry the name the specification's OpenRPC document gives it, and
 * must be advertised on a fresh MCP session.
 *
 * Both halves were wrong at once and invisible the same way. The tools were named
 * `shopware-ucp-*`, so a spec-following agent that looked for `create_cart` found nothing;
 * and they sat in a toolset an agent had to enable first, so `tools/list` on `/ucp/mcp`
 * showed only the toolset meta-tools. Neither shows up as an error -- an empty or unfamiliar
 * list is a valid answer -- which is how both reached a live store.
 *
 * The pinned `mcp.openrpc.json` in the SDK's schema tree is the specification's own list, so
 * the assertion is against that file rather than a copy of the names kept here.
 */
#[CoversNothing]
final class UcpMcpToolNamesTest extends TestCase
{
    /**
     * Tools this plugin offers beyond the specification's method list, with the reason. An
     * entry here is a claim that the release under test defines no method for it.
     *
     * @var array<string, string>
     */
    private const NOT_IN_SPEC = [
        // The OpenRPC document folds discount codes into update_cart's `discounts`; this tool
        // exists so an agent can apply a code without resending every line item.
        'apply_discount' => 'discount application is a cart.update concern in the OpenRPC document',
    ];

    private const DISCOVERY_GROUP_ATTRIBUTE = 'Shopware\Core\Framework\Mcp\Attribute\McpToolGroup';

    public function testEveryToolIsNamedAsTheSpecificationNamesIt(): void
    {
        $specMethods = $this->specMethodNames();
        self::assertNotEmpty($specMethods);

        foreach ($this->toolClasses() as $class) {
            $name = $this->toolName($class);

            if (isset(self::NOT_IN_SPEC[$name])) {
                self::assertNotContains($name, $specMethods, \sprintf('%s is excused as not in the spec, but the spec defines it -- drop the excuse.', $name));

                continue;
            }

            self::assertContains($name, $specMethods, \sprintf('%s is named "%s", which the %s OpenRPC document does not define.', $class, $name, UcpProtocol::VERSION));
        }
    }

    public function testEveryToolIsAdvertisedOnAFreshSession(): void
    {
        foreach ($this->toolClasses() as $class) {
            $groups = [];
            foreach ((new \ReflectionClass($class))->getAttributes() as $attribute) {
                // Read the arguments without instantiating: the attribute class exists on trunk
                // only, and this test runs on every supported Shopware version.
                if (self::DISCOVERY_GROUP_ATTRIBUTE === $attribute->getName()) {
                    $groups[] = $attribute->getArguments()[0] ?? $attribute->getArguments()['group'] ?? null;
                }
            }

            self::assertSame(['discovery'], $groups, \sprintf('%s must sit in the always-advertised "discovery" group, or a spec-following agent never sees it.', $class));
        }
    }

    /**
     * @return list<class-string>
     */
    private function toolClasses(): array
    {
        $files = glob(__DIR__.'/../../src/Ucp/Mcp/Tool/*Tool.php');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);

        $classes = [];
        foreach ($files as $file) {
            $class = 'Swag\AgenticCommerce\Ucp\Mcp\Tool\\'.basename($file, '.php');
            self::assertTrue(class_exists($class), $class);
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * @param class-string $class
     */
    private function toolName(string $class): string
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(McpTool::class);
        self::assertCount(1, $attributes, $class);
        $name = $attributes[0]->newInstance()->name;

        return $name;
    }

    /**
     * @return list<string>
     */
    private function specMethodNames(): array
    {
        $core = InstalledVersions::getInstallPath('ucp-php-sdk/core');
        self::assertIsString($core);
        $path = $core.'/resources/schema/pinned/'.UcpProtocol::VERSION.'/services/shopping/mcp.openrpc.json';
        self::assertFileExists($path);

        $document = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['methods'] ?? null);

        $names = [];
        foreach ($document['methods'] as $method) {
            self::assertIsArray($method);
            self::assertIsString($method['name'] ?? null);
            $names[] = $method['name'];
        }

        return $names;
    }
}
