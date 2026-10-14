<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Readiness\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessReport;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessService;
use Swag\AgenticCommerce\AgenticFiles\Readiness\ReadinessFileState;
use Swag\AgenticCommerce\Ucp\Command\SalesChannelResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The selection options and the report of the `swag-agentic-commerce:readiness:*` commands. Their
 * names and exit codes are called by installers, so they are a stable interface.
 *
 * @internal
 */
#[Package('discovery')]
abstract class AbstractReadinessCommand extends Command
{
    public function __construct(
        protected readonly BasicAiReadinessService $readinessService,
        private readonly SalesChannelResolver $salesChannelResolver,
    ) {
        parent::__construct();
    }

    /**
     * @param ?list<string> $salesChannelIds null selects every eligible sales channel
     */
    abstract protected function report(?array $salesChannelIds, InputInterface $input): BasicAiReadinessReport;

    abstract protected function summarize(SymfonyStyle $io, BasicAiReadinessReport $report): void;

    protected function configure(): void
    {
        $this
            ->addOption('sales-channel', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Storefront or Headless sales channel id or name (repeatable).')
            ->addOption('all-storefronts', null, InputOption::VALUE_NONE, 'Every Storefront and Headless sales channel.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $salesChannelIds = $this->selectedSalesChannelIds($input, $io);
        if (false === $salesChannelIds) {
            return self::INVALID;
        }

        $report = $this->report($salesChannelIds, $input);

        if (!$report->coreSupported) {
            $io->warning(BasicAiReadinessReport::CORE_SUPPORT_MISSING_MESSAGE);

            return self::SUCCESS;
        }

        if ([] === $report->salesChannels) {
            $io->warning('There is no Storefront or Headless sales channel. Nothing was changed.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($report->salesChannels as $salesChannel) {
            foreach ($salesChannel->files as $fileName => $state) {
                $rows[] = [$salesChannel->name ?? '', $salesChannel->id, $fileName, $this->label($state, $report)];
            }
        }
        $io->table(['Sales channel', 'Id', 'File', 'State'], $rows);

        $this->summarize($io, $report);

        return self::SUCCESS;
    }

    /**
     * @return list<string>|false|null null selects every eligible sales channel, false is invalid input that was already reported
     */
    private function selectedSalesChannelIds(InputInterface $input, SymfonyStyle $io): array|false|null
    {
        /** @var list<string> $requested */
        $requested = $input->getOption('sales-channel');
        $all = true === $input->getOption('all-storefronts');

        if ($all && [] !== $requested) {
            $io->error('Pass either --sales-channel or --all-storefronts, not both.');

            return false;
        }

        if ($all) {
            return null;
        }

        if ([] === $requested) {
            $io->error('Select the sales channels with --sales-channel=<id|name> (repeatable) or --all-storefronts:');
            $this->salesChannelResolver->renderTable($io);

            return false;
        }

        $salesChannelIds = [];
        foreach ($requested as $value) {
            $salesChannelId = $this->salesChannelResolver->resolve($input, $io, $value, false);
            if (!\is_string($salesChannelId)) {
                return false;
            }

            $salesChannelIds[] = $salesChannelId;
        }

        return array_values(array_unique($salesChannelIds));
    }

    private function label(ReadinessFileState $state, BasicAiReadinessReport $report): string
    {
        return match ($state) {
            ReadinessFileState::Missing => $report->dryRun ? 'missing, would be created' : 'missing',
            ReadinessFileState::Created => 'created',
            ReadinessFileState::Enabled => 'enabled',
            ReadinessFileState::DisabledByMerchant => $this->disabledByMerchantLabel(),
        };
    }

    protected function cliContext(): Context
    {
        // createCLIContext() arrived in Shopware 6.6.
        // @phpstan-ignore function.alreadyNarrowedType, shopware.cliContext, staticMethod.notFound
        return method_exists(Context::class, 'createCLIContext') ? Context::createCLIContext() : Context::createDefaultContext();
    }

    protected function disabledByMerchantLabel(): string
    {
        return 'disabled by merchant';
    }
}
