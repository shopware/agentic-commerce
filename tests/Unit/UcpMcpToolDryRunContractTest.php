<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins which UCP MCP tools declare `dryRun`.
 *
 * An eval harness classifies a tool mechanically: one whose inputSchema declares
 * `dryRun` is called with `dryRun: true` forced on, anything else that mutates is
 * never called and gets graded on tool name alone. The schema is generated from the
 * `__invoke` signature, so this asserts the signatures directly — a new tool, or a
 * dropped parameter, fails here instead of silently downgrading the suite to
 * selection-only grading.
 *
 * @internal
 */
final class UcpMcpToolDryRunContractTest extends TestCase
{
    private const TOOL_NAMESPACE = 'Swag\\AgenticCommerce\\Ucp\\Mcp\\Tool\\';

    /**
     * @return iterable<string, array{string}>
     */
    public static function mutatingToolProvider(): iterable
    {
        foreach ([
            'UcpCartCreateTool',
            'UcpCartUpdateTool',
            'UcpCartCancelTool',
            'UcpCheckoutCreateTool',
            'UcpCheckoutUpdateTool',
            'UcpCheckoutCompleteTool',
            'UcpCheckoutCancelTool',
            'UcpDiscountApplyTool',
        ] as $tool) {
            yield $tool => [$tool];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readOnlyToolProvider(): iterable
    {
        foreach ([
            'UcpCartGetTool',
            'UcpCatalogLookupTool',
            'UcpCatalogSearchTool',
            'UcpCheckoutGetTool',
            'UcpOrderGetTool',
        ] as $tool) {
            yield $tool => [$tool];
        }
    }

    #[DataProvider('mutatingToolProvider')]
    #[Test]
    public function testMutatingToolsDeclareABooleanDryRunDefaultingToTrue(string $tool): void
    {
        $parameter = $this->parameters($tool)['dryRun'] ?? null;

        self::assertNotNull($parameter, \sprintf('%s mutates state and must declare a dryRun parameter.', $tool));

        $type = $parameter->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame('bool', $type->getName(), 'dryRun must be a bool so the generated schema declares a boolean.');
        self::assertTrue($parameter->isDefaultValueAvailable(), 'dryRun must be optional.');
        self::assertTrue($parameter->getDefaultValue(), 'dryRun must default to true so an agent previews before committing.');
    }

    #[DataProvider('mutatingToolProvider')]
    #[Test]
    public function testDryRunIsTheLastParameterSoAddingItDidNotReorderTheSchema(string $tool): void
    {
        $names = array_keys($this->parameters($tool));

        self::assertSame('dryRun', end($names));
    }

    #[DataProvider('readOnlyToolProvider')]
    #[Test]
    public function testReadOnlyToolsDoNotDeclareDryRun(string $tool): void
    {
        self::assertArrayNotHasKey(
            'dryRun',
            $this->parameters($tool),
            \sprintf('%s does not mutate state, so a dryRun parameter would only confuse an agent.', $tool),
        );
    }

    #[Test]
    public function testEveryToolInTheNamespaceIsClassified(): void
    {
        $classified = [];
        foreach ([...self::mutatingToolProvider(), ...self::readOnlyToolProvider()] as $row) {
            $classified[] = $row[0];
        }

        // UcpMcpToolContext and UcpCheckoutCompletionPreview are shared helpers, not
        // tools, and fall outside this glob by name.
        $files = glob(__DIR__.'/../../src/Ucp/Mcp/Tool/*Tool.php');
        self::assertNotFalse($files);

        $found = array_map(static fn (string $file): string => basename($file, '.php'), $files);

        sort($classified);
        sort($found);

        self::assertSame($classified, $found, 'A tool was added or removed without updating this contract.');
    }

    /**
     * @return array<string, \ReflectionParameter>
     */
    private function parameters(string $tool): array
    {
        $parameters = [];
        foreach ((new \ReflectionMethod(self::TOOL_NAMESPACE.$tool, '__invoke'))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        return $parameters;
    }
}
