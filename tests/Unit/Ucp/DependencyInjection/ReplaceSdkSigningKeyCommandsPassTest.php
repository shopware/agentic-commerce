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
use Swag\AgenticCommerce\Ucp\DependencyInjection\ReplaceSdkSigningKeyCommandsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * @internal
 */
#[CoversClass(ReplaceSdkSigningKeyCommandsPass::class)]
class ReplaceSdkSigningKeyCommandsPassTest extends TestCase
{
    /** @var list<string> */
    private const SDK_COMMANDS = [
        'Ucp\\Sdk\\Symfony\\Command\\GenerateSigningKeyCommand',
        'Ucp\\Sdk\\Symfony\\Command\\ListSigningKeysCommand',
        'Ucp\\Sdk\\Symfony\\Command\\ShowPublicSigningKeysCommand',
        'Ucp\\Sdk\\Symfony\\Command\\RetireSigningKeyCommand',
        'Ucp\\Sdk\\Symfony\\Command\\DeleteSigningKeyCommand',
    ];

    public function testItRemovesTheSdkSigningKeyCommandDefinitions(): void
    {
        $container = new ContainerBuilder();
        foreach (self::SDK_COMMANDS as $id) {
            $container->setDefinition($id, new Definition(\stdClass::class));
        }
        // An unrelated SDK command must survive so the pass stays narrowly scoped.
        $container->setDefinition('Ucp\\Sdk\\Symfony\\Command\\StorageCleanupCommand', new Definition(\stdClass::class));

        (new ReplaceSdkSigningKeyCommandsPass())->process($container);

        foreach (self::SDK_COMMANDS as $id) {
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

        foreach (self::SDK_COMMANDS as $id) {
            static::assertFalse($container->hasDefinition($id));
        }
    }
}
