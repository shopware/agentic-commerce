<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\DependencyInjection;

use Swag\AgenticCommerce\Ucp\Http\ConfiguredUrlSafetyValidatorFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;

/**
 * Replaces the SDK bundle's compile-time {@see UrlSafetyValidator} (built from
 * the `ucp_sdk.allowed_profile_hosts` semantic config, which the plugin leaves
 * empty) with one built at runtime by
 * {@see ConfiguredUrlSafetyValidatorFactory} from the plugin's per-channel and
 * global UCP allowlists. Without this, the admin-managed
 * `remoteProfileAllowlist` never reaches the profile fetcher or webhook
 * dispatcher, and every remote platform profile fetch fails with
 * "Profile host ... is not allowed" no matter what is configured.
 *
 * Done as a compiler pass (rather than in services.php) so the definition
 * override deterministically wins over the SDK bundle's own regardless of
 * bundle load order.
 *
 * Shared on purpose. The host list is a union over every sales channel, so
 * rebuilding it per injection would put that query on the request path for a
 * value that only changes when an operator edits a config. The cost is that an
 * edit reaches a process only through a new container: immediate for a web
 * request, but a `messenger:consume` worker would keep refusing an outbound
 * order webhook to a host allowlisted minutes ago for as long as it runs. The
 * validator is `final` with a readonly host list, so it can be made neither
 * `kernel.reset`-aware nor usefully unshared -- its consumers are themselves
 * shared and hold their instance. {@see \Swag\AgenticCommerce\Ucp\Config\UcpConfigService::saveConfig()}
 * closes that window from the other end, by asking the workers to stop when an
 * allowlist changes.
 *
 * @internal
 */
class ReplaceSdkUrlSafetyValidatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (
            !$container->hasDefinition(UrlSafetyValidator::class)
            || !$container->hasDefinition(ConfiguredUrlSafetyValidatorFactory::class)
        ) {
            return;
        }

        $definition = new Definition(UrlSafetyValidator::class);
        $definition->setFactory([new Reference(ConfiguredUrlSafetyValidatorFactory::class), 'create']);

        $container->setDefinition(UrlSafetyValidator::class, $definition);
    }
}
