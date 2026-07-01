<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'swag-agentic-commerce:ucp:config:set',
    description: 'Sets the non-UI UCP config fields (signature policy, allowlists, delivery) for a sales channel.',
)]
final class UcpConfigSetCommand extends Command
{
    /**
     * Array option name => UcpConfig payload key.
     */
    private const LIST_OPTIONS = [
        'agent-allowlist' => 'agentAllowlist',
        'remote-profile-allowlist' => 'remoteProfileAllowlist',
        'platform-allowlist' => 'platformAllowlist',
        'embedded-allowed-origins' => 'embeddedAllowedOrigins',
        'embedded-frame-ancestors' => 'embeddedFrameAncestors',
    ];

    /**
     * Nullable-string option name => UcpConfig payload key (empty value clears to null).
     */
    private const NULLABLE_OPTIONS = [
        'webhook-url-override' => 'webhookUrlOverride',
        'continue-url-template' => 'continueUrlTemplate',
    ];

    public function __construct(private readonly UcpConfigService $configService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sales-channel-id', null, InputOption::VALUE_REQUIRED, 'Sales channel id (required).');
        $this->addOption('signature-policy', null, InputOption::VALUE_REQUIRED, 'Signature policy: strict, log or off.');
        $this->addOption('idempotency', null, InputOption::VALUE_REQUIRED, 'Require idempotency keys for write requests (true/false).');

        foreach (array_keys(self::LIST_OPTIONS) as $option) {
            $this->addOption($option, null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, \sprintf('%s host/origin (repeatable). Omit to leave unchanged.', $option));
        }

        foreach (array_keys(self::NULLABLE_OPTIONS) as $option) {
            $this->addOption($option, null, InputOption::VALUE_REQUIRED, \sprintf('%s (pass an empty value to clear).', $option));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $salesChannelId = $input->getOption('sales-channel-id');
        if (!\is_string($salesChannelId) || '' === $salesChannelId) {
            $io->error('The --sales-channel-id option is required.');

            return self::INVALID;
        }

        $payload = $this->collectPayload($input);
        if ([] === $payload) {
            $io->warning('No fields to update — pass at least one option (e.g. --signature-policy=strict).');

            return self::INVALID;
        }

        try {
            // saveConfig merges this partial payload over the stored config, so
            // the Exposure fields managed in the admin UI are preserved.
            $config = $this->configService->saveConfig($payload, $salesChannelId);
        } catch (UcpConfigException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->success(\sprintf('Updated UCP config for sales channel %s.', $salesChannelId));
        $io->writeln((string) json_encode($config->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectPayload(InputInterface $input): array
    {
        $payload = [];

        $signaturePolicy = $input->getOption('signature-policy');
        if (\is_string($signaturePolicy) && '' !== $signaturePolicy) {
            $payload['signaturePolicy'] = $signaturePolicy;
        }

        $idempotency = $input->getOption('idempotency');
        if (\is_string($idempotency) && '' !== $idempotency) {
            $payload['idempotencyRequired'] = filter_var($idempotency, \FILTER_VALIDATE_BOOLEAN);
        }

        foreach (self::LIST_OPTIONS as $option => $key) {
            $values = $input->getOption($option);
            if (\is_array($values) && [] !== $values) {
                $payload[$key] = array_values($values);
            }
        }

        foreach (self::NULLABLE_OPTIONS as $option => $key) {
            $value = $input->getOption($option);
            if (null !== $value) {
                $payload[$key] = '' === $value ? null : $value;
            }
        }

        return $payload;
    }
}
