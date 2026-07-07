<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Command\UcpSigningKeyDeleteCommand;
use Swag\AgenticCommerce\Ucp\Command\UcpSigningKeyGenerateCommand;
use Swag\AgenticCommerce\Ucp\Command\UcpSigningKeyListCommand;
use Swag\AgenticCommerce\Ucp\Command\UcpSigningKeyRetireCommand;
use Swag\AgenticCommerce\Ucp\Command\UcpSigningKeyShowPublicCommand;
use Swag\AgenticCommerce\Ucp\DependencyInjection\ReplaceSdkSigningKeyCommandsPass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ucp\Sdk\Symfony\Command\DeleteSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\GenerateSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\ListSigningKeysCommand;
use Ucp\Sdk\Symfony\Command\RetireSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\ShowPublicSigningKeysCommand;

/**
 * @internal
 */
#[CoversClass(ReplaceSdkSigningKeyCommandsPass::class)]
class ReplaceSdkSigningKeyCommandsPassTest extends TestCase
{
    /**
     * The plugin subclass that must take over each SDK command's name.
     *
     * @var array<class-string, class-string>
     */
    private const SUBCLASS_TO_SDK_PARENT = [
        UcpSigningKeyGenerateCommand::class => GenerateSigningKeyCommand::class,
        UcpSigningKeyListCommand::class => ListSigningKeysCommand::class,
        UcpSigningKeyShowPublicCommand::class => ShowPublicSigningKeysCommand::class,
        UcpSigningKeyRetireCommand::class => RetireSigningKeyCommand::class,
        UcpSigningKeyDeleteCommand::class => DeleteSigningKeyCommand::class,
    ];

    public function testItRemovesTheSdkSigningKeyCommandDefinitions(): void
    {
        $container = new ContainerBuilder();
        foreach (ReplaceSdkSigningKeyCommandsPass::SDK_COMMAND_SERVICES as $id) {
            $container->setDefinition($id, new Definition(\stdClass::class));
        }
        // An unrelated SDK command must survive so the pass stays narrowly scoped.
        $container->setDefinition('Ucp\\Sdk\\Symfony\\Command\\StorageCleanupCommand', new Definition(\stdClass::class));

        (new ReplaceSdkSigningKeyCommandsPass())->process($container);

        foreach (ReplaceSdkSigningKeyCommandsPass::SDK_COMMAND_SERVICES as $id) {
            static::assertFalse($container->hasDefinition($id), "Expected SDK command {$id} to be removed");
        }
        static::assertTrue(
            $container->hasDefinition('Ucp\\Sdk\\Symfony\\Command\\StorageCleanupCommand'),
            'Unrelated SDK commands must not be removed',
        );
    }

    public function testItIsANoOpWhenTheSdkCommandsAreAbsent(): void
    {
        $container = new ContainerBuilder();

        (new ReplaceSdkSigningKeyCommandsPass())->process($container);

        foreach (ReplaceSdkSigningKeyCommandsPass::SDK_COMMAND_SERVICES as $id) {
            static::assertFalse($container->hasDefinition($id));
        }
    }

    /**
     * Guards the silent-duplicate failure mode: the pass removes SDK commands by
     * service id (== FQCN). If the removal list ever drifts from the subclasses
     * that replace them, the SDK command survives next to the plugin's under the
     * same name. The list must therefore be exactly the subclasses' parents.
     */
    public function testTheRemovalListIsExactlyTheParentsOfTheReplacingSubclasses(): void
    {
        $expected = array_values(self::SUBCLASS_TO_SDK_PARENT);
        sort($expected);

        $actual = ReplaceSdkSigningKeyCommandsPass::SDK_COMMAND_SERVICES;
        sort($actual);

        static::assertSame($expected, $actual);
    }

    /**
     * Each subclass must extend the SDK command it replaces and register under
     * the same command name — otherwise removing the SDK service would leave a
     * differently-named command missing, or leave the SDK's name unclaimed.
     */
    public function testEachSubclassExtendsItsSdkParentUnderTheSameCommandName(): void
    {
        foreach (self::SUBCLASS_TO_SDK_PARENT as $subclass => $parent) {
            static::assertSame(
                $parent,
                get_parent_class($subclass),
                "{$subclass} must extend {$parent}",
            );
            static::assertSame(
                $this->commandName($parent),
                $this->commandName($subclass),
                "{$subclass} must keep the command name of {$parent}",
            );
        }
    }

    /**
     * @param class-string $class
     */
    private function commandName(string $class): string
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(AsCommand::class);
        static::assertNotEmpty($attributes, "{$class} must declare #[AsCommand]");

        return (string) $attributes[0]->newInstance()->name;
    }
}
