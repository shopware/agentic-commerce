<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyException;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Config\Validation\Finding;
use Swag\AgenticCommerce\Ucp\Config\Validation\Severity;
use Swag\AgenticCommerce\Ucp\Config\Validation\UcpConfigValidator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainView;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelView;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Configures a sales channel for UCP in one step.
 *
 * Getting a shop to its first UCP request used to mean three places and an oral tradition: the
 * Exposure tab in the Administration, `ucp:config:set` for the allowlists and the signature
 * policy, a signing key, an environment variable for development mode, and then a second web
 * server because the agent profile had to come from somewhere. Each step was documented; the
 * sequence was not. This command is the sequence.
 *
 * It composes what already exists rather than duplicating it: the config is written through
 * {@see UcpConfigService::saveConfig()} with the same merge semantics as `ucp:config:set`, the
 * key through {@see UcpSigningKeyService}, and the result is checked by the same
 * {@see UcpConfigValidator} that `ucp:config:validate` runs. The Administration remains the
 * place to change any of it afterwards.
 *
 * `--dev` picks defaults for a laptop: signature policy `log` (unsigned requests are accepted
 * and noted), and the channel's own domain hosts plus `localhost` on both allowlists, so the
 * shop can act as its own agent through the SDK's development-mode shortcut. Without `--dev`
 * the defaults are production ones: policy `strict`, and only the hosts named with
 * `--agent-host` are admitted.
 *
 * @internal
 */
#[AsCommand(
    name: 'ucp:setup',
    description: 'Configure a sales channel for UCP in one step: exposure, security defaults, signing key, validation and the first request.',
)]
#[Package('framework')]
final class UcpSetupCommand extends Command
{
    public function __construct(
        private readonly SalesChannelResolver $salesChannelResolver,
        private readonly SalesChannelViewProvider $salesChannelViewProvider,
        private readonly UcpConfigService $configService,
        private readonly UcpSigningKeyService $signingKeyService,
        private readonly UcpConfigValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Sales channel id or name (omit to pick interactively).')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Local development defaults: signature policy "log", the channel\'s own hosts and localhost on the allowlists.')
            ->addOption('agent-host', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Host of a platform allowed to talk to this channel, without scheme (repeatable). Required for a usable production setup.')
            ->addOption('capabilities', null, InputOption::VALUE_REQUIRED, \sprintf('Comma-separated capability keys to expose. Default: %s.', implode(',', UcpCapabilityCatalog::defaultConfigKeys())))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would be written and stop; nothing is persisted.')
            ->setHelp(<<<'HELP'
                One command from "plugin installed" to "first UCP request", for one sales channel.

                  # A laptop: the shop accepts itself as the agent, unsigned requests are logged, not refused
                  <info>bin/console ucp:setup --sales-channel=Storefront --dev</info>

                  # Production: strict signatures, and only the named platform may talk to the channel
                  <info>bin/console ucp:setup --sales-channel=Storefront --agent-host=agent.example.com</info>

                  # See the resulting config without writing it
                  <info>bin/console ucp:setup --sales-channel=Storefront --dev --dry-run</info>

                Afterwards, <info>ucp:config:show</info>, <info>ucp:config:set</info> and the Administration change
                individual values; <info>ucp:config:validate</info> re-runs the same readiness checks.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $salesChannelId = $this->salesChannelResolver->resolve($input, $io, $input->getOption('sales-channel'), false);
        if (false === $salesChannelId || null === $salesChannelId) {
            return self::INVALID;
        }

        $channel = $this->channel($salesChannelId);
        if (null === $channel) {
            $io->error(\sprintf('Sales channel %s was not found.', $salesChannelId));

            return self::INVALID;
        }

        $dev = (bool) $input->getOption('dev');
        $agentHosts = array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            (array) $input->getOption('agent-host'),
        )));
        $domainHosts = $this->domainHosts($channel);

        try {
            $payload = $this->payload($dev, $agentHosts, $domainHosts, $input->getOption('capabilities'));
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return self::INVALID;
        }

        $io->title(\sprintf('UCP setup for %s (%s)', $channel->name ?? $salesChannelId, $salesChannelId));

        if ((bool) $input->getOption('dry-run')) {
            $io->section('Would write');
            $io->writeln((string) json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
            $io->note('Dry run: nothing was persisted and no key was generated.');

            return self::SUCCESS;
        }

        try {
            $config = $this->configService->saveConfig($payload, $salesChannelId);
        } catch (UcpConfigException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $io->writeln(\sprintf('  <info>✓</info> UCP exposure and security defaults written (%s).', $dev ? 'development' : 'production'));

        try {
            $keys = $this->signingKeyService->all($salesChannelId);
            if ([] === $keys) {
                $created = $this->signingKeyService->create($salesChannelId);
                $keys = [$created];
                $io->writeln(\sprintf('  <info>✓</info> Signing key generated (kid %s).', (string) ($created['kid'] ?? '?')));
            } else {
                $io->writeln(\sprintf('  <info>✓</info> Signing key present (%d existing key%s kept).', \count($keys), 1 === \count($keys) ? '' : 's'));
            }
        } catch (UcpSigningKeyException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $findings = $this->validator->validate(
            $salesChannelId,
            $channel->name ?? '',
            $config,
            $keys,
            array_map(static fn (SalesChannelDomainView $domain): array => $domain->jsonSerialize(), $channel->domains),
        );
        $this->renderFindings($io, $findings);

        $this->renderSummary($io, $config, $channel);
        $this->renderNextSteps($io, $dev, $agentHosts, $domainHosts, $salesChannelId);

        return $this->hasErrors($findings) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param list<string> $agentHosts
     * @param list<string> $domainHosts
     *
     * @return array<string, mixed>
     */
    private function payload(bool $dev, array $agentHosts, array $domainHosts, mixed $capabilities): array
    {
        $payload = [
            'active' => true,
            'idempotencyRequired' => true,
        ];

        if ($dev) {
            // The shop's own hosts, so it can act as its own agent (the SDK accepts its own
            // profile in development mode), plus localhost for a profile served from a
            // local process. Both lists, because the SDK checks the profile host against
            // one and the agent domain against the other.
            $hosts = array_values(array_unique(['localhost', ...$domainHosts, ...$agentHosts]));
            $payload['signaturePolicy'] = 'log';
            $payload['platformAllowlist'] = $hosts;
            $payload['agentAllowlist'] = $hosts;
        } else {
            // Both lists are written even when no host was named. saveConfig() merges this
            // payload over the stored config, so omitting them would carry an earlier `--dev`
            // run's `localhost` and own-domain hosts into a production setup -- the opposite of
            // what this branch promises, and visible only to an operator who reads the summary
            // carefully. An empty allowlist admits nobody, which is the safe end of the mistake.
            $hosts = array_values(array_unique($agentHosts));
            $payload['signaturePolicy'] = 'strict';
            $payload['platformAllowlist'] = $hosts;
            $payload['agentAllowlist'] = $hosts;
        }

        if (\is_string($capabilities) && '' !== trim($capabilities)) {
            $keys = array_values(array_filter(array_map('trim', explode(',', $capabilities))));
            $unknown = array_diff($keys, UcpCapabilityCatalog::allConfigKeys());
            if ([] !== $unknown) {
                throw new \InvalidArgumentException(\sprintf('Unknown capability key(s) %s; known keys are %s.', implode(', ', $unknown), implode(', ', UcpCapabilityCatalog::allConfigKeys())));
            }

            $payload['enabledCapabilities'] = $keys;
        }

        return $payload;
    }

    private function channel(string $salesChannelId): ?SalesChannelView
    {
        // createDefaultContext(), like every other call site here including ucp:config:validate
        // which lists the same channels: createCLIContext() arrived after 6.5 and 6.6, and this
        // plugin supports ~6.5 || ~6.6 || ~6.7, where it is an undefined static method.
        foreach ($this->salesChannelViewProvider->all(Context::createDefaultContext()) as $channel) {
            if ($channel->id === $salesChannelId) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function domainHosts(SalesChannelView $channel): array
    {
        $hosts = [];
        foreach ($channel->domains as $domain) {
            $host = parse_url($domain->url, \PHP_URL_HOST);
            if (\is_string($host) && '' !== $host) {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @param list<Finding> $findings
     */
    private function renderFindings(SymfonyStyle $io, array $findings): void
    {
        if ([] === $findings) {
            $io->writeln('  <info>✓</info> Readiness check passed.');

            return;
        }

        $io->section('Readiness check');
        foreach ($findings as $finding) {
            $tag = match ($finding->severity) {
                Severity::Error => 'error',
                Severity::Warning => 'comment',
                Severity::Info => 'info',
            };
            $io->writeln(\sprintf('  <%s>%-7s</%s> [%s] %s', $tag, $finding->severity->label(), $tag, $finding->code, $finding->message));
            if (null !== $finding->remediation) {
                $io->writeln(\sprintf('          ↳ %s', $finding->remediation));
            }
        }
    }

    private function renderSummary(SymfonyStyle $io, UcpConfig $config, SalesChannelView $channel): void
    {
        $io->section('Result');
        $profileUrls = array_map(
            static fn (SalesChannelDomainView $domain): string => rtrim($domain->url, '/').'/.well-known/ucp',
            $channel->domains,
        );
        $io->definitionList(
            ['Profile URL' => [] === $profileUrls ? '(no domain on this sales channel yet)' : implode("\n", $profileUrls)],
            ['Signature policy' => $config->signaturePolicy],
            ['Idempotency required' => $config->idempotencyRequired ? 'yes' : 'no'],
            ['Platform allowlist' => [] === $config->platformAllowlist ? '(none: no platform can talk to this channel yet)' : implode(', ', $config->platformAllowlist)],
            ['Agent allowlist' => [] === $config->agentAllowlist ? '(none)' : implode(', ', $config->agentAllowlist)],
            ['Capabilities' => implode(', ', $config->enabledCapabilities)],
            ['Transports' => implode(', ', $config->enabledTransports)],
        );
    }

    /**
     * @param list<string> $agentHosts
     * @param list<string> $domainHosts
     */
    private function renderNextSteps(SymfonyStyle $io, bool $dev, array $agentHosts, array $domainHosts, string $salesChannelId): void
    {
        $io->section('Next');
        $baseUri = [] === $domainHosts ? 'https://<your-shop-domain>' : '<first domain above, without the path>';

        if ($dev) {
            $io->listing([
                'Turn on the SDK\'s development mode so the shop accepts its own profile as the agent: set '
                .'<info>SWAG_AGENTIC_COMMERCE_UCP_PROFILE_FETCHING_DEVELOPMENT_MODE=1</info> in the environment and clear the cache. '
                .'Never in production: it also admits plain http and loopback profile hosts.',
                \sprintf('Print a first request and run it: <info>bin/console ucp:dev:request catalog.search --base-uri=%s</info>', $baseUri),
                \sprintf('Re-check any time: <info>bin/console ucp:config:validate --sales-channel=%s</info>', $salesChannelId),
            ]);

            return;
        }

        $steps = [];
        if ([] === $agentHosts) {
            $steps[] = 'No platform is allowed yet. Add the platform\'s profile host once you know it: '
                .\sprintf('<info>bin/console ucp:config:set --sales-channel=%s --platform-allowlist=agent.example.com --agent-allowlist=agent.example.com</info>', $salesChannelId);
        }
        $steps[] = 'Give the platform the profile URL above; it discovers everything else from there.';
        $steps[] = \sprintf('Before go-live: <info>bin/console ucp:config:validate --sales-channel=%s --strict</info>', $salesChannelId);
        $io->listing($steps);
    }

    /**
     * @param list<Finding> $findings
     */
    private function hasErrors(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (Severity::Error === $finding->severity) {
                return true;
            }
        }

        return false;
    }
}
