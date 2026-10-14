<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Readiness\Command;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\AgenticFiles\Readiness\BasicAiReadinessReport;
use Swag\AgenticCommerce\AgenticFiles\Readiness\ReadinessFileState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(
    name: 'swag-agentic-commerce:readiness:status',
    description: 'Lists per sales channel whether llms.txt and AGENTS.md are missing, enabled or switched off.',
)]
#[Package('discovery')]
final class ReadinessStatusCommand extends AbstractReadinessCommand
{
    protected function report(?array $salesChannelIds, InputInterface $input): BasicAiReadinessReport
    {
        return $this->readinessService->status($salesChannelIds, $this->cliContext());
    }

    protected function summarize(SymfonyStyle $io, BasicAiReadinessReport $report): void
    {
        $missing = $report->countFiles(ReadinessFileState::Missing);
        if ($missing > 0) {
            $io->note(\sprintf('%d file(s) missing. Create them with swag-agentic-commerce:readiness:enable.', $missing));
        }
    }
}
