<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelView;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;

/**
 * Assembles the readiness page in one read.
 *
 * Every input already exists: {@see SalesChannelViewProvider} filters to the
 * channels UCP can be activated on, {@see UcpConfigService} says which of them
 * are exposed, {@see ChannelFindingsResolver} says what is still wrong with the
 * exposed ones, and {@see OnboardingMetricsInterface} supplies the two counts.
 *
 * @internal
 */
#[Package('framework')]
final class ShopReadinessProvider
{
    public function __construct(
        private readonly SalesChannelViewProvider $salesChannelViewProvider,
        private readonly UcpConfigService $configService,
        private readonly ChannelFindingsResolver $findingsResolver,
        private readonly OnboardingMetricsInterface $metrics,
    ) {
    }

    public function readiness(Context $context): ShopReadiness
    {
        // A deactivated sales channel serves nobody, so exposing UCP on it would
        // achieve nothing; it is left out of the readiness picture entirely
        // rather than offered as work. The `ucp:*` commands still list it, which
        // is why the filter lives here and not in the shared view provider.
        $views = array_values(array_filter(
            $this->salesChannelViewProvider->all($context),
            static fn (SalesChannelView $view): bool => $view->active,
        ));
        $salesChannelIds = array_map(static fn (SalesChannelView $view): string => $view->id, $views);

        $configs = $this->configService->getConfigs($salesChannelIds);
        $productCounts = $this->metrics->activeProductCounts($salesChannelIds);

        $channels = [];
        $preparedCount = 0;
        $hasError = false;

        foreach ($views as $view) {
            $config = $configs[$view->id] ?? null;
            $prepared = null !== $config && $config->active;

            $findings = [];
            if ($prepared) {
                ++$preparedCount;
                $findings = $this->findingsResolver->resolve($view->id, $view->name, $config, $view);
                $hasError = $hasError || $this->findingsResolver->hasError($findings);
            }

            $channels[] = new ChannelReadiness(
                $view->id,
                $view->name,
                $view->domains,
                $productCounts[$view->id] ?? 0,
                $prepared,
                $findings,
            );
        }

        $agenticSalesChannelCount = $this->metrics->agenticSalesChannelCount();

        return new ShopReadiness(
            $this->status($preparedCount, $agenticSalesChannelCount, $hasError),
            $preparedCount,
            $agenticSalesChannelCount,
            $channels,
        );
    }

    /**
     * Nothing exposed is a setup problem. Anything exposed but broken, or a shop
     * that has not connected a provider yet, needs an action. Only a shop that
     * finished every step with no error-level finding is reported as ready.
     */
    private function status(int $preparedCount, int $agenticSalesChannelCount, bool $hasError): string
    {
        if (0 === $preparedCount) {
            return ShopReadiness::STATUS_SETUP_NEEDED;
        }

        if ($hasError || 0 === $agenticSalesChannelCount) {
            return ShopReadiness::STATUS_ACTION_NEEDED;
        }

        return ShopReadiness::STATUS_READY;
    }
}
