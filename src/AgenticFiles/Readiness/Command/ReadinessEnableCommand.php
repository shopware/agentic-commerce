<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Readiness\Command;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessReport;
use Swag\AgenticCommerce\AgenticFiles\Readiness\ReadinessFileState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(
    name: 'swag-agentic-commerce:readiness:enable',
    description: 'Switches on llms.txt and AGENTS.md for Storefront and Headless sales channels, without enabling UCP.',
)]
#[Package('discovery')]
final class ReadinessEnableCommand extends AbstractReadinessCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change; nothing is written.')
            ->setHelp(<<<'HELP'
                Creates the missing <info>llms.txt</info> and <info>AGENTS.md</info> files of the selected sales channels, so
                AI assistants can discover the shop. Agent checkout stays off: the UCP config is not touched.

                A file that already exists is left as it is, so a file switched off in the Administration stays
                off, and a second run changes nothing. Needs Shopware 6.7.12 or newer; on older versions the
                command changes nothing and says so.

                  <info>bin/console swag-agentic-commerce:readiness:enable --all-storefronts</info>
                  <info>bin/console swag-agentic-commerce:readiness:enable --sales-channel=Storefront --dry-run</info>
                HELP);
    }

    protected function report(?array $salesChannelIds, InputInterface $input): BasicAiReadinessReport
    {
        return $this->readinessService->enable($salesChannelIds, true === $input->getOption('dry-run'), $this->cliContext());
    }

    protected function disabledByMerchantLabel(): string
    {
        return 'disabled by merchant, left off';
    }

    protected function summarize(SymfonyStyle $io, BasicAiReadinessReport $report): void
    {
        $disabled = $report->countFiles(ReadinessFileState::DisabledByMerchant);
        if ($disabled > 0) {
            $io->note(\sprintf('%d file(s) stay off because they were switched off for the sales channel.', $disabled));
        }

        if ($report->dryRun) {
            $io->note(\sprintf('Dry run: %d file(s) would be created. Nothing was written.', $report->countFiles(ReadinessFileState::Missing)));

            return;
        }

        $created = $report->countFiles(ReadinessFileState::Created);
        $io->success(0 === $created ? 'Nothing to change.' : \sprintf('Created %d file(s).', $created));
    }
}
