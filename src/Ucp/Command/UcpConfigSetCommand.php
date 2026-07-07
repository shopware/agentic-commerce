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
    name: 'ucp:config:set',
    description: 'Sets the non-UI UCP config fields (signature policy, allowlists, delivery) for a sales channel.',
)]
final class UcpConfigSetCommand extends Command
{
    /**
     * @var list<string>
     */
    private const SIGNATURE_POLICIES = ['strict', 'log', 'off'];

    /**
     * @var list<string>
     */
    private const BOOLEAN_LITERALS = ['true', '1', 'yes', 'false', '0', 'no'];

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

    public function __construct(
        private readonly UcpConfigService $configService,
        private readonly SalesChannelResolver $salesChannelResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Sales channel id or name (required; omit to pick interactively).')
            ->addOption('signature-policy', null, InputOption::VALUE_REQUIRED, 'Inbound signature policy: strict, log or off. Example: --signature-policy=strict')
            ->addOption('idempotency', null, InputOption::VALUE_REQUIRED, 'Require idempotency keys on write requests: true or false. Example: --idempotency=true')
            ->addOption('agent-allowlist', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Host allowed to act as a UCP agent — bare host, no scheme (repeatable). Omit to leave unchanged. Example: --agent-allowlist=agent.example.com')
            ->addOption('remote-profile-allowlist', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Host the SDK may fetch remote agent profiles from — bare host (repeatable). Omit to leave unchanged. Example: --remote-profile-allowlist=profiles.example.com')
            ->addOption('platform-allowlist', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Platform host allowed to integrate — bare host (repeatable). Omit to leave unchanged. Example: --platform-allowlist=chatgpt.com')
            ->addOption('embedded-allowed-origins', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Origin (scheme + host) allowed to embed the UCP checkout (repeatable). Omit to leave unchanged. Example: --embedded-allowed-origins=https://chatgpt.com')
            ->addOption('embedded-frame-ancestors', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'CSP frame-ancestors source allowed to iframe the embedded page (repeatable). Omit to leave unchanged. Example: --embedded-frame-ancestors=https://chatgpt.com')
            ->addOption('webhook-url-override', null, InputOption::VALUE_REQUIRED, 'Absolute https URL for outbound webhooks; its host must be in an allowlist. Pass an empty value to clear. Example: --webhook-url-override=https://hooks.example.com/ucp')
            ->addOption('continue-url-template', null, InputOption::VALUE_REQUIRED, 'Absolute URL the shopper returns to after checkout; supports {checkoutId}, {cartId}, {salesChannelId}. Pass an empty value to clear. Example: --continue-url-template="https://shop.example.com/return?id={checkoutId}"');

        $this->setHelp($this->helpText());
    }

    private function helpText(): string
    {
        return <<<'HELP'
            Updates only the options you pass; every other field — including the Exposure
            settings managed in the Administration — is left untouched.

            Examples:

              # Relax signature checking to log-only while testing an integration
              <info>bin/console ucp:config:set --sales-channel="Storefront" --signature-policy=log</info>

              # Allow one agent host and require idempotency on writes
              <info>bin/console ucp:config:set --sales-channel=Storefront --agent-allowlist=agent.example.com --idempotency=true</info>

              # Allow an embedded checkout to be framed by ChatGPT
              <info>bin/console ucp:config:set --sales-channel=Storefront --embedded-allowed-origins=https://chatgpt.com --embedded-frame-ancestors=https://chatgpt.com</info>

              # Set a post-checkout return URL with a placeholder
              <info>bin/console ucp:config:set --sales-channel=Storefront --continue-url-template="https://shop.example.com/checkout/done?id={checkoutId}"</info>

              # Clear the webhook override (empty value)
              <info>bin/console ucp:config:set --sales-channel=Storefront --webhook-url-override=</info>

            Run <info>ucp:channels</info> to list sales-channel ids and their UCP exposure, and
            <info>ucp:config:show --sales-channel=...</info> to inspect the current values.
            HELP;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $salesChannelId = $this->salesChannelResolver->resolve($input, $io, $input->getOption('sales-channel'), false);
        if (false === $salesChannelId || null === $salesChannelId) {
            return self::INVALID;
        }

        try {
            $payload = $this->collectPayload($input);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return self::INVALID;
        }

        if ([] === $payload) {
            $io->error('Nothing to set — pass at least one field option, e.g. --signature-policy=strict. Run the command with --help for all options and examples.');

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
        if (\is_string($signaturePolicy)) {
            $payload['signaturePolicy'] = $this->signaturePolicyValue($signaturePolicy);
        }

        $idempotency = $input->getOption('idempotency');
        if (\is_string($idempotency)) {
            $payload['idempotencyRequired'] = $this->booleanOptionValue('idempotency', $idempotency);
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

    private function signaturePolicyValue(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (\in_array($normalized, self::SIGNATURE_POLICIES, true)) {
            return $normalized;
        }

        throw new \InvalidArgumentException(\sprintf('Invalid --signature-policy value "%s"; expected one of: %s.', $value, implode(', ', self::SIGNATURE_POLICIES)));
    }

    private function booleanOptionValue(string $option, string $value): bool
    {
        $normalized = strtolower(trim($value));
        $parsed = match ($normalized) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => null,
        };

        if (null !== $parsed) {
            return $parsed;
        }

        throw new \InvalidArgumentException(\sprintf('Invalid --%s value "%s"; expected one of: %s.', $option, $value, implode(', ', self::BOOLEAN_LITERALS)));
    }
}
