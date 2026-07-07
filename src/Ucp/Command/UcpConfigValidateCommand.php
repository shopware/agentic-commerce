<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Command;

use Shopware\Core\Framework\Context;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Config\Validation\Finding;
use Swag\AgenticCommerce\Ucp\Config\Validation\Severity;
use Swag\AgenticCommerce\Ucp\Config\Validation\UcpConfigValidator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ucp:config:validate',
    description: 'Checks each sales channel\'s UCP config for readiness and security issues.',
)]
final class UcpConfigValidateCommand extends Command
{
    public function __construct(
        private readonly SalesChannelViewProvider $salesChannelViewProvider,
        private readonly SalesChannelResolver $salesChannelResolver,
        private readonly UcpConfigService $configService,
        private readonly UcpSigningKeyService $signingKeyService,
        private readonly UcpConfigValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sales-channel', null, InputOption::VALUE_REQUIRED, 'Validate a single sales channel (id or name). Omit to validate all channels.')
            ->addOption('only-active', null, InputOption::VALUE_NONE, 'Skip channels where UCP is off.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit non-zero on warnings as well as errors (useful in CI).')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: txt or json.', 'txt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = (string) $input->getOption('format');
        if (!\in_array($format, ['txt', 'json'], true)) {
            $io->error(\sprintf('Unknown format "%s"; use txt or json.', $format));

            return self::INVALID;
        }

        $channels = $this->salesChannelViewProvider->all(Context::createDefaultContext());

        $selected = $input->getOption('sales-channel');
        if (\is_string($selected) && '' !== $selected) {
            $salesChannelId = $this->salesChannelResolver->resolve($input, $io, $selected, false);
            if (false === $salesChannelId || null === $salesChannelId) {
                return self::INVALID;
            }

            $channels = array_values(array_filter(
                $channels,
                static fn (array $channel): bool => ($channel['id'] ?? null) === $salesChannelId,
            ));
        }

        $onlyActive = (bool) $input->getOption('only-active');

        if ([] === $channels) {
            $io->warning('No sales channels found.');

            return self::SUCCESS;
        }

        /** @var list<Finding> $findings */
        $findings = [];
        /** @var list<array{id: string, name: string, findings: list<Finding>}> $checked */
        $checked = [];

        foreach ($channels as $channel) {
            $id = (string) ($channel['id'] ?? '');
            $name = (string) ($channel['name'] ?? '');

            try {
                $config = $this->configService->getConfig($id);
            } catch (UcpConfigException $exception) {
                $finding = new Finding(
                    $id,
                    $name,
                    Severity::Error,
                    'invalid_config',
                    $exception->getMessage(),
                    \sprintf('Fix the persisted UCP config for this sales channel, then rerun bin/console ucp:config:validate --sales-channel=%s.', $id),
                );

                $checked[] = ['id' => $id, 'name' => $name, 'findings' => [$finding]];
                $findings[] = $finding;

                continue;
            }

            if ($onlyActive && !$config->active) {
                continue;
            }

            $domains = \is_array($channel['domains'] ?? null) ? $channel['domains'] : [];
            $channelFindings = $this->validator->validate(
                $id,
                $name,
                $config,
                $this->signingKeyService->all($id),
                $domains,
            );

            $checked[] = ['id' => $id, 'name' => $name, 'findings' => $channelFindings];
            $findings = array_merge($findings, $channelFindings);
        }

        if ('json' === $format) {
            $io->writeln((string) json_encode(
                array_map(static fn (Finding $finding): array => $finding->toArray(), $findings),
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->renderText($io, $checked);
        }

        return $this->exitCode($findings, (bool) $input->getOption('strict'));
    }

    /**
     * @param list<array{id: string, name: string, findings: list<Finding>}> $checked
     */
    private function renderText(SymfonyStyle $io, array $checked): void
    {
        $errors = 0;
        $warnings = 0;

        foreach ($checked as $channel) {
            $io->section(\sprintf('%s (%s)', $channel['name'], $channel['id']));

            if ([] === $channel['findings']) {
                $io->writeln('  <info>OK</info>      no issues found');

                continue;
            }

            foreach ($channel['findings'] as $finding) {
                $tag = $this->tag($finding->severity);
                $io->writeln(\sprintf('  <%s>%-7s</%s> [%s] %s', $tag, $finding->severity->label(), $tag, $finding->code, $finding->message));
                if (null !== $finding->remediation) {
                    $io->writeln(\sprintf('          ↳ %s', $finding->remediation));
                }

                if (Severity::Error === $finding->severity) {
                    ++$errors;
                } elseif (Severity::Warning === $finding->severity) {
                    ++$warnings;
                }
            }
        }

        $io->newLine();
        $io->writeln(\sprintf(
            'Checked %d channel(s): <comment>%d error(s)</comment>, <comment>%d warning(s)</comment>.',
            \count($checked),
            $errors,
            $warnings,
        ));
    }

    private function tag(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'comment',
            Severity::Info => 'info',
        };
    }

    /**
     * @param list<Finding> $findings
     */
    private function exitCode(array $findings, bool $strict): int
    {
        foreach ($findings as $finding) {
            if (Severity::Error === $finding->severity) {
                return self::FAILURE;
            }
        }

        if ($strict) {
            foreach ($findings as $finding) {
                if (Severity::Warning === $finding->severity) {
                    return self::FAILURE;
                }
            }
        }

        return self::SUCCESS;
    }
}
