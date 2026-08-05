<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Attribute\AsCommand;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;
use Ucp\Sdk\Symfony\Command\GenerateSigningKeyCommand;

/**
 * Sales-channel-aware variant of the SDK's signing-key generate command.
 *
 * @internal
 */
#[AsCommand(
    name: 'ucp:signing-keys:generate',
    description: 'Generate and store a UCP signing key for a sales channel.',
)]
#[Package('framework')]
final class UcpSigningKeyGenerateCommand extends GenerateSigningKeyCommand
{
    use InteractsWithSalesChannelTenant;

    public function __construct(
        SalesChannelResolver $salesChannelResolver,
        SigningKeyManagerInterface $signingKeyManager,
        ManagedSigningKeyRepositoryInterface $repository,
    ) {
        parent::__construct($signingKeyManager, $repository);
        $this->salesChannelResolver = $salesChannelResolver;
    }
}
