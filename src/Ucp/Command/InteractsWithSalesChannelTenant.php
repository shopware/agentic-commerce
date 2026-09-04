<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Maps the SDK signing-key commands' "tenant" onto a Shopware sales channel.
 *
 * The SDK identifies key owners by an opaque tenant identifier; in Shopware
 * that tenant is the sales-channel id. This trait replaces the SDK's generic
 * `--tenant` option with a friendlier `--sales-channel` (accepting an id OR a
 * name, with an interactive picker) and resolves it back to the tenant the SDK
 * commands persist against. Omitting the option targets the global/default
 * scope.
 *
 * Used by the plugin subclasses of the SDK `ucp:signing-keys:*` commands, both
 * {@see InteractsWithSigningKeyTenant::configureTenantOption()} and
 * {@see InteractsWithSigningKeyTenant::resolveTenantIdentifier()} are overridden
 * here so the SDK's own `execute()` transparently scopes to a sales channel.
 */
#[Package('framework')]
trait InteractsWithSalesChannelTenant
{
    protected SalesChannelResolver $salesChannelResolver;

    protected function configureTenantOption(): void
    {
        $this->addOption(
            'sales-channel',
            null,
            InputOption::VALUE_REQUIRED,
            'Sales channel id or name (omit to pick interactively / target the global scope).',
        );
    }

    protected function resolveTenantIdentifier(InputInterface $input, OutputInterface $output): ?string
    {
        $value = $input->getOption('sales-channel');
        $resolved = $this->salesChannelResolver->resolve(
            $input,
            new SymfonyStyle($input, $output),
            \is_string($value) ? $value : null,
            true,
        );

        if (false === $resolved) {
            // The resolver already printed the reason and the available channels.
            throw new RuntimeException('Could not resolve the requested sales channel.');
        }

        return $resolved;
    }
}
