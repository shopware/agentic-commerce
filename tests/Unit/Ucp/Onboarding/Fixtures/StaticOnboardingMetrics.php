<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Onboarding\Fixtures;

use Swag\AgenticCommerce\Ucp\Onboarding\OnboardingMetricsInterface;

/** @internal */
final class StaticOnboardingMetrics implements OnboardingMetricsInterface
{
    /**
     * @param array<string, int> $productCounts
     */
    public function __construct(
        private readonly array $productCounts = [],
        private readonly int $agenticSalesChannelCount = 0,
    ) {
    }

    public function activeProductCounts(array $salesChannelIds): array
    {
        return array_intersect_key($this->productCounts, array_flip($salesChannelIds));
    }

    public function agenticSalesChannelCount(): int
    {
        return $this->agenticSalesChannelCount;
    }
}
