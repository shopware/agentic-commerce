<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Lets the plugin's sales-channel-aware signing-key commands replace the SDK
 * defaults.
 *
 * The SDK bundle registers a generic `ucp:signing-keys:*` command set keyed by
 * an opaque `--tenant`. The plugin ships subclasses of the same commands that
 * swap `--tenant` for `--sales-channel` (see
 * {@see \Swag\AgenticCommerce\Ucp\Command\InteractsWithSalesChannelTenant}).
 * Removing the SDK command service definitions here leaves the plugin
 * subclasses as the only services bearing those command names, so operators see
 * a single, Shopware-friendly command set instead of two competing ones.
 *
 * @internal
 */
class ReplaceSdkSigningKeyCommandsPass implements CompilerPassInterface
{
    private const SDK_COMMAND_SERVICES = [
        'Ucp\\Sdk\\Symfony\\Command\\GenerateSigningKeyCommand',
        'Ucp\\Sdk\\Symfony\\Command\\ListSigningKeysCommand',
        'Ucp\\Sdk\\Symfony\\Command\\ShowPublicSigningKeysCommand',
        'Ucp\\Sdk\\Symfony\\Command\\RetireSigningKeyCommand',
        'Ucp\\Sdk\\Symfony\\Command\\DeleteSigningKeyCommand',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::SDK_COMMAND_SERVICES as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }
    }
}
