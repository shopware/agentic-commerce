<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Log\Package;

/**
 * Counts the readiness page reports next to each sales channel.
 *
 * Behind an interface so {@see ShopReadinessProvider} stays unit-testable
 * without a database, the way the config layer does it.
 *
 * @internal
 */
#[Package('framework')]
interface OnboardingMetricsInterface
{
    /**
     * Active products visible in each channel, keyed by sales-channel id. A
     * channel with no visible product is absent rather than zero.
     *
     * @param list<string> $salesChannelIds
     *
     * @return array<string, int>
     */
    public function activeProductCounts(array $salesChannelIds): array;

    /**
     * Active sales channels of the Agentic Commerce type, which is what
     * "connected to an AI provider" means on the readiness page.
     */
    public function agenticSalesChannelCount(): int;
}
