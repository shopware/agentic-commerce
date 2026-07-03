<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ucp:channels',
    description: 'Lists the sales channels, their ids and UCP exposure (use with the other ucp:* commands).',
)]
final class UcpChannelsCommand extends Command
{
    public function __construct(
        private readonly SalesChannelResolver $salesChannelResolver,
        private readonly UcpConfigService $configService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $channels = $this->salesChannelResolver->all();
        if ([] === $channels) {
            $io->warning('No sales channels found.');

            return self::SUCCESS;
        }

        $configs = $this->configService->getConfigs(array_map(
            static fn (array $channel): string => $channel['id'],
            $channels,
        ));

        $rows = [];
        foreach ($channels as $channel) {
            $exposed = isset($configs[$channel['id']]) && $configs[$channel['id']]->active;
            $rows[] = [$channel['name'], $channel['id'], $exposed ? 'exposed' : 'off'];
        }

        $io->table(['Name', 'Id', 'UCP'], $rows);

        return self::SUCCESS;
    }
}
