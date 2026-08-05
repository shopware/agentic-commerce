<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Attribute\AsCommand;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Symfony\Command\RetireSigningKeyCommand;

/**
 * Sales-channel-aware variant of the SDK's signing-key retire command.
 *
 * @internal
 */
#[AsCommand(
    name: 'ucp:signing-keys:retire',
    description: 'Retire a UCP signing key for a sales channel (kept for verification, no longer used to sign).',
)]
#[Package('framework')]
final class UcpSigningKeyRetireCommand extends RetireSigningKeyCommand
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
