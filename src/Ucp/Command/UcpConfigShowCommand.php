<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'swag-agentic-commerce:ucp:config:show',
    description: 'Prints the resolved UCP config for a sales channel.',
)]
final class UcpConfigShowCommand extends Command
{
    public function __construct(private readonly UcpConfigService $configService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel-id', null, InputOption::VALUE_REQUIRED, 'Sales channel id (omit for the global/default scope).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $salesChannelId = $input->getOption('sales-channel-id');
        $salesChannelId = \is_string($salesChannelId) && '' !== $salesChannelId ? $salesChannelId : null;

        $config = $this->configService->getConfig($salesChannelId)->toArray();

        $io->title(\sprintf('UCP config — %s', $salesChannelId ?? 'global/default'));
        $io->writeln((string) json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
