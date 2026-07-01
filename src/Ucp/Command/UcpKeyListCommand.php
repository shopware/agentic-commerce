<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'swag-agentic-commerce:ucp:key:list',
    description: 'Lists the UCP signing keys for a sales channel.',
)]
final class UcpKeyListCommand extends Command
{
    public function __construct(private readonly UcpSigningKeyService $signingKeyService)
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
        $salesChannelId = $this->nullableOption($input->getOption('sales-channel-id'));

        $keys = $this->signingKeyService->all($salesChannelId);
        if ([] === $keys) {
            $io->warning('No signing keys found. One is created automatically when UCP is turned on.');

            return self::SUCCESS;
        }

        $io->table(
            ['Key id', 'Algorithm', 'Status', 'Created at'],
            array_map(
                static fn (array $key): array => [
                    (string) ($key['kid'] ?? ''),
                    (string) ($key['algorithm'] ?? ''),
                    (string) ($key['status'] ?? ''),
                    (string) ($key['createdAt'] ?? ''),
                ],
                $keys,
            ),
        );

        return self::SUCCESS;
    }

    private function nullableOption(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
