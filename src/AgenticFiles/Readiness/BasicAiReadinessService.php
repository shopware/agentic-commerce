<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Readiness;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelView;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;

/**
 * Switches on the passive discovery files on Storefront and Headless sales channels without
 * touching the UCP config. Only missing core file rows are created, so a file the merchant
 * switched off stays off.
 *
 * @internal
 */
#[Package('discovery')]
final class BasicAiReadinessService
{
    /**
     * Core's canonical spellings; core matches file names case-insensitively, so an `agents.md`
     * row written by the UCP activation path counts as present.
     */
    public const FILE_NAMES = ['llms.txt', 'AGENTS.md'];

    public function __construct(
        private readonly AgenticFilesCoreBridgeInterface $agenticFilesCoreBridge,
        private readonly SalesChannelViewProvider $salesChannelViewProvider,
    ) {
    }

    /**
     * @param ?list<string> $salesChannelIds null selects every eligible sales channel
     */
    public function status(?array $salesChannelIds, Context $context): BasicAiReadinessReport
    {
        return $this->run($salesChannelIds, false, false, $context);
    }

    /**
     * @param ?list<string> $salesChannelIds null selects every eligible sales channel
     */
    public function enable(?array $salesChannelIds, bool $dryRun, Context $context): BasicAiReadinessReport
    {
        return $this->run($salesChannelIds, !$dryRun, $dryRun, $context);
    }

    /**
     * @param ?list<string> $salesChannelIds
     */
    private function run(?array $salesChannelIds, bool $write, bool $dryRun, Context $context): BasicAiReadinessReport
    {
        if (!$this->agenticFilesCoreBridge->supportsCoreFiles()) {
            return BasicAiReadinessReport::withoutCoreSupport($dryRun);
        }

        $salesChannels = $this->select($salesChannelIds, $context);
        $states = $this->agenticFilesCoreBridge->fileStates(
            array_map(static fn (SalesChannelView $salesChannel): string => $salesChannel->id, $salesChannels),
            self::FILE_NAMES,
        );

        $reports = [];
        foreach ($salesChannels as $salesChannel) {
            $fileStates = $states[$salesChannel->id] ?? [];
            $created = [];

            $missing = array_values(array_filter(self::FILE_NAMES, static fn (string $fileName): bool => !isset($fileStates[$fileName])));
            if ($write && [] !== $missing) {
                $created = $this->agenticFilesCoreBridge->insertMissingFiles($salesChannel->id, $missing);
                if (\count($created) < \count($missing)) {
                    // Someone else created a row in between; report what it holds rather than guessing.
                    $fileStates = $this->agenticFilesCoreBridge->fileStates([$salesChannel->id], self::FILE_NAMES)[$salesChannel->id] ?? [];
                }
            }

            $reports[] = new SalesChannelReadiness($salesChannel->id, $salesChannel->name, $this->fileReport($fileStates, $created));
        }

        return new BasicAiReadinessReport(true, $dryRun, $reports);
    }

    /**
     * @param ?list<string> $salesChannelIds
     *
     * @return list<SalesChannelView>
     */
    private function select(?array $salesChannelIds, Context $context): array
    {
        $eligible = [];
        foreach ($this->salesChannelViewProvider->all($context) as $salesChannel) {
            if ($salesChannel->transactional) {
                $eligible[$salesChannel->id] = $salesChannel;
            }
        }

        if (null === $salesChannelIds) {
            return array_values($eligible);
        }

        $notEligible = array_values(array_filter(
            array_unique($salesChannelIds),
            static fn (string $salesChannelId): bool => !isset($eligible[$salesChannelId]),
        ));
        if ([] !== $notEligible) {
            throw BasicAiReadinessException::salesChannelsNotEligible($notEligible);
        }

        return array_values(array_map(
            static fn (string $salesChannelId): SalesChannelView => $eligible[$salesChannelId],
            array_unique($salesChannelIds),
        ));
    }

    /**
     * @param array<string, bool> $fileStates
     * @param list<string>        $created
     *
     * @return array<string, ReadinessFileState>
     */
    private function fileReport(array $fileStates, array $created): array
    {
        $files = [];
        foreach (self::FILE_NAMES as $fileName) {
            $files[$fileName] = match (true) {
                \in_array($fileName, $created, true) => ReadinessFileState::Created,
                !isset($fileStates[$fileName]) => ReadinessFileState::Missing,
                $fileStates[$fileName] => ReadinessFileState::Enabled,
                default => ReadinessFileState::DisabledByMerchant,
            };
        }

        return $files;
    }
}
