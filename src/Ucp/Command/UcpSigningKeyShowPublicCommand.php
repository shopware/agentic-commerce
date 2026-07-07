<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Attribute\AsCommand;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;
use Ucp\Sdk\Symfony\Command\ShowPublicSigningKeysCommand;

/**
 * Sales-channel-aware variant of the SDK's public-signing-key command.
 *
 * @internal
 */
#[AsCommand(
    name: 'ucp:signing-keys:show-public',
    description: 'Show the public UCP signing keys published in discovery for a sales channel.',
)]
#[Package('framework')]
final class UcpSigningKeyShowPublicCommand extends ShowPublicSigningKeysCommand
{
    use InteractsWithSalesChannelTenant;

    public function __construct(
        SalesChannelResolver $salesChannelResolver,
        ManagedSigningKeyRepositoryInterface $repository,
        SigningKeyManagerInterface $signingKeyManager,
    ) {
        parent::__construct($repository, $signingKeyManager);
        $this->salesChannelResolver = $salesChannelResolver;
    }
}
