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
    name: 'swag-agentic-commerce:ucp:key:delete',
    description: 'Permanently deletes a UCP signing key.',
)]
final class UcpKeyDeleteCommand extends Command
{
    public function __construct(private readonly UcpSigningKeyService $signingKeyService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel-id', null, InputOption::VALUE_REQUIRED, 'Sales channel id (omit for the global/default scope).');
        $this->addOption('kid', null, InputOption::VALUE_REQUIRED, 'Key id to delete.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $kid = $input->getOption('kid');
        if (!\is_string($kid) || '' === $kid) {
            $io->error('The --kid option is required.');

            return self::INVALID;
        }

        if (!$this->signingKeyService->delete($this->nullableOption($input->getOption('sales-channel-id')), $kid)) {
            $io->error(\sprintf('Signing key "%s" not found.', $kid));

            return self::FAILURE;
        }

        $io->success(\sprintf('Deleted signing key "%s".', $kid));

        return self::SUCCESS;
    }

    private function nullableOption(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
