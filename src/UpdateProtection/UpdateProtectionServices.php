<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\UpdateProtection;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the update protection outside services.php, which registers nothing while the SDK is
 * unusable, because core replaces the plugin's files in exactly that state as well.
 *
 * @internal
 */
#[Package('framework')]
final class UpdateProtectionServices
{
    private const MARKER_FILE = '/var/swag-agentic-commerce-preload.marker';

    private const RESTART_SIGNAL_POOL_ID = 'cache.messenger.restart_workers_signal';

    public static function register(ContainerBuilder $container): void
    {
        $pluginRoot = \dirname(__DIR__, 2);

        $container->register(ClassPreloader::class, ClassPreloader::class)
            ->setArguments([
                $pluginRoot,
                '%kernel.project_dir%'.self::MARKER_FILE,
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);

        // A closure keeps the pool from being created in every request.
        $container->register(UpdateProtectionSubscriber::class, UpdateProtectionSubscriber::class)
            ->setArguments([
                new Reference(ClassPreloader::class),
                $pluginRoot,
                new ServiceClosureArgument(new Reference(self::RESTART_SIGNAL_POOL_ID, ContainerInterface::NULL_ON_INVALID_REFERENCE)),
            ])
            ->addTag('kernel.event_subscriber');
    }
}
