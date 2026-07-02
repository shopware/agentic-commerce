<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Ucp\Sdk\Symfony\Command\DeleteSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\GenerateSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\ListSigningKeysCommand;
use Ucp\Sdk\Symfony\Command\RetireSigningKeyCommand;
use Ucp\Sdk\Symfony\Command\ShowPublicSigningKeysCommand;

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
 * The SDK registers each command under its FQCN as the service id, so the class
 * constants below double as the service ids. Referencing the classes (rather
 * than string literals) keeps this list in lockstep with the subclasses that
 * extend them: if the SDK renames or drops a command class, this file — and the
 * subclass extending it — fail static analysis instead of silently leaving the
 * SDK command in place next to the plugin's (a duplicate command name).
 *
 * @internal
 */
class ReplaceSdkSigningKeyCommandsPass implements CompilerPassInterface
{
    /** @var list<class-string> */
    public const SDK_COMMAND_SERVICES = [
        GenerateSigningKeyCommand::class,
        ListSigningKeysCommand::class,
        ShowPublicSigningKeysCommand::class,
        RetireSigningKeyCommand::class,
        DeleteSigningKeyCommand::class,
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
