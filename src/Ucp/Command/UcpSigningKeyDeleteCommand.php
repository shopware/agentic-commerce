<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Attribute\AsCommand;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Symfony\Command\DeleteSigningKeyCommand;

/**
 * Sales-channel-aware variant of the SDK's signing-key delete command.
 *
 * @internal
 */
#[AsCommand(
    name: 'ucp:signing-keys:delete',
    description: 'Permanently delete a UCP signing key for a sales channel.',
)]
#[Package('framework')]
final class UcpSigningKeyDeleteCommand extends DeleteSigningKeyCommand
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
