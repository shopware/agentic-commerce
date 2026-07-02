<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ucp:channels',
    description: 'Lists the sales channels and their ids (use with the other ucp:* commands).',
)]
final class UcpChannelsCommand extends Command
{
    public function __construct(private readonly SalesChannelResolver $salesChannelResolver)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->salesChannelResolver->renderTable(new SymfonyStyle($input, $output));

        return self::SUCCESS;
    }
}
