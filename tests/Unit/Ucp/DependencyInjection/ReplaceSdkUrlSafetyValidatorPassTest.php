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
use Swag\AgenticCommerce\Ucp\DependencyInjection\ReplaceSdkUrlSafetyValidatorPass;
use Swag\AgenticCommerce\Ucp\Http\ConfiguredUrlSafetyValidatorFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;

/**
 * @internal
 */
#[CoversClass(ReplaceSdkUrlSafetyValidatorPass::class)]
class ReplaceSdkUrlSafetyValidatorPassTest extends TestCase
{
    public function testItReplacesTheSdkValidatorWithTheFactoryBuiltOne(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(UrlSafetyValidator::class, new Definition(UrlSafetyValidator::class, [
            [],
            null,
            false,
        ]));
        $container->setDefinition(
            ConfiguredUrlSafetyValidatorFactory::class,
            new Definition(ConfiguredUrlSafetyValidatorFactory::class),
        );

        $pass = new ReplaceSdkUrlSafetyValidatorPass();
        $pass->process($container);

        $definition = $container->getDefinition(UrlSafetyValidator::class);
        static::assertSame(UrlSafetyValidator::class, $definition->getClass());
        static::assertSame([], $definition->getArguments(), 'The static host list must not survive the replacement');

        $factory = $definition->getFactory();
        static::assertIsArray($factory);
        static::assertInstanceOf(Reference::class, $factory[0]);
        static::assertSame(ConfiguredUrlSafetyValidatorFactory::class, (string) $factory[0]);
        static::assertSame('create', $factory[1]);
    }

    public function testItIsANoOpWithoutTheSdkValidatorDefinition(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(
            ConfiguredUrlSafetyValidatorFactory::class,
            new Definition(ConfiguredUrlSafetyValidatorFactory::class),
        );

        $pass = new ReplaceSdkUrlSafetyValidatorPass();
        $pass->process($container);

        static::assertFalse($container->hasDefinition(UrlSafetyValidator::class));
    }

    public function testItIsANoOpWithoutTheFactoryDefinition(): void
    {
        $container = new ContainerBuilder();
        $sdkDefinition = new Definition(UrlSafetyValidator::class, [[], null, false]);
        $container->setDefinition(UrlSafetyValidator::class, $sdkDefinition);

        $pass = new ReplaceSdkUrlSafetyValidatorPass();
        $pass->process($container);

        static::assertSame($sdkDefinition, $container->getDefinition(UrlSafetyValidator::class));
    }
}
