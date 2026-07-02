<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Attribute\AsCommand;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Symfony\Command\ListSigningKeysCommand;

/**
 * Sales-channel-aware variant of the SDK's signing-key list command.
 *
 * @internal
 */
#[AsCommand(
    name: 'ucp:signing-keys:list',
    description: 'List managed UCP signing keys for a sales channel.',
)]
#[Package('framework')]
final class UcpSigningKeyListCommand extends ListSigningKeysCommand
{
    use InteractsWithSalesChannelTenant;

    public function __construct(
        SalesChannelResolver $salesChannelResolver,
        ManagedSigningKeyRepositoryInterface $repository,
    ) {
        parent::__construct($repository);
        $this->salesChannelResolver = $salesChannelResolver;
    }
}
