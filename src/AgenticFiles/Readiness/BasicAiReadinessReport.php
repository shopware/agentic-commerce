<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Readiness;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('discovery')]
final class BasicAiReadinessReport implements \JsonSerializable
{
    public const CORE_SUPPORT_MISSING_MESSAGE = 'This Shopware version does not serve sales channel files; Basic AI Readiness needs Shopware 6.7.12 or newer. Nothing was changed.';

    /**
     * @param list<SalesChannelReadiness> $salesChannels
     */
    public function __construct(
        public readonly bool $coreSupported,
        public readonly bool $dryRun,
        public readonly array $salesChannels,
    ) {
    }

    public static function withoutCoreSupport(bool $dryRun): self
    {
        return new self(false, $dryRun, []);
    }

    public function countFiles(ReadinessFileState $state): int
    {
        $count = 0;
        foreach ($this->salesChannels as $salesChannel) {
            foreach ($salesChannel->files as $fileState) {
                if ($fileState === $state) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * @return array{coreSupported: bool, message: string|null, dryRun: bool, salesChannels: list<SalesChannelReadiness>}
     */
    public function jsonSerialize(): array
    {
        return [
            'coreSupported' => $this->coreSupported,
            'message' => $this->coreSupported ? null : self::CORE_SUPPORT_MISSING_MESSAGE,
            'dryRun' => $this->dryRun,
            'salesChannels' => $this->salesChannels,
        ];
    }
}
