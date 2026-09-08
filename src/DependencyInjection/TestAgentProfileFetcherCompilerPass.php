<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\DependencyInjection;

use Swag\AgenticCommerce\Ucp\Test\StaticAgentProfileFetcher;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;

/**
 * In the `test` environment only, replaces the SDK's HTTP agent-profile fetcher with the
 * test-supplied {@see StaticAgentProfileFetcher}, so the functional suite can negotiate the
 * UCP request-context handshake against a fixed profile without an HTTP fetch.
 *
 * Done as a compiler pass (rather than in services.php) so the alias override deterministically
 * wins over the SDK bundle's own `AgentProfileFetcherInterface` alias regardless of bundle load
 * order. A no-op outside the `test` environment, so deployed dev/prod keep the real fetcher.
 *
 * @internal
 */
class TestAgentProfileFetcherCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ('test' !== $container->getParameter('kernel.environment')) {
            return;
        }

        if (!$container->hasAlias(AgentProfileFetcherInterface::class) && !$container->hasDefinition(AgentProfileFetcherInterface::class)) {
            return;
        }

        $container->setDefinition(
            StaticAgentProfileFetcher::class,
            (new Definition(StaticAgentProfileFetcher::class))->setPublic(true),
        );

        $container->removeAlias(AgentProfileFetcherInterface::class);
        $container->setAlias(AgentProfileFetcherInterface::class, StaticAgentProfileFetcher::class)
            ->setPublic(true);
    }
}
