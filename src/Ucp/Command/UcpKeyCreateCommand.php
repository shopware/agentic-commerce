<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyException;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'swag-agentic-commerce:ucp:key:create',
    description: 'Creates a UCP signing key for a sales channel.',
)]
final class UcpKeyCreateCommand extends Command
{
    public function __construct(private readonly UcpSigningKeyService $signingKeyService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel-id', null, InputOption::VALUE_REQUIRED, 'Sales channel id (omit for the global/default scope).');
        $this->addOption('kid', null, InputOption::VALUE_REQUIRED, 'Key id (optional; auto-generated when omitted).');
        $this->addOption('algorithm', null, InputOption::VALUE_REQUIRED, 'Signing algorithm.', 'ES256');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $key = $this->signingKeyService->create(
                $this->nullableOption($input->getOption('sales-channel-id')),
                $this->nullableOption($input->getOption('kid')),
                (string) $input->getOption('algorithm'),
            );
        } catch (UcpSigningKeyException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success(\sprintf('Created signing key "%s" (%s).', (string) $key['kid'], (string) $key['algorithm']));

        return self::SUCCESS;
    }

    private function nullableOption(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
