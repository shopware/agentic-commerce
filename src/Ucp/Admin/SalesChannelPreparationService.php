<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Admin;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\System\SalesChannel\AbstractSalesChannelTypeResolver;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Ucp\Config\UcpActivationWriterInterface;

/**
 * Prepares sales channels for agentic commerce: enables UCP (with the default
 * capabilities) and the AI files for each given channel. Only transactional
 * (Storefront/Headless) channels can be prepared; anything else is skipped so a
 * bad id list cannot activate UCP on a channel type that does not support it.
 *
 * @internal
 */
#[Package('framework')]
final class SalesChannelPreparationService
{
    public function __construct(
        private readonly UcpActivationWriterInterface $activationWriter,
        private readonly AbstractSalesChannelTypeResolver $salesChannelTypeResolver,
    ) {
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return list<string> the ids that were prepared
     */
    public function prepare(array $salesChannelIds): array
    {
        if ([] === $salesChannelIds) {
            return [];
        }

        $classifications = $this->salesChannelTypeResolver->resolveMany($salesChannelIds);

        $prepared = [];
        foreach ($salesChannelIds as $salesChannelId) {
            $classification = $classifications[$salesChannelId] ?? SalesChannelTypeClassification::Other;
            if (!$classification->isTransactional()) {
                continue;
            }

            $this->activationWriter->activate($salesChannelId);
            $prepared[] = $salesChannelId;
        }

        return $prepared;
    }
}
