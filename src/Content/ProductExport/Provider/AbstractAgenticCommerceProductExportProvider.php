<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Provider;

use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Base class for Agentic Commerce product export providers.
 *
 * Handles adding common fields (provider name, referral code, affiliate/campaign codes)
 * to the Twig render context. Concrete providers only need to implement
 * {@see buildProviderContext()} for their format-specific fields.
 */
abstract class AbstractAgenticCommerceProductExportProvider
{
    /**
     * Query parameter used on storefront URLs to attribute incoming traffic to
     * the originating agentic commerce sales channel. Mirrors
     * `Shopware\Core\Content\ProductExport\Tracking\SalesChannelTrackingListener::QUERY_PARAM`
     * in 6.7.10+ so that the same URL is produced with or without this plugin.
     */
    public const REFERRING_SALES_CHANNEL_QUERY_PARAM = 'referringSalesChannel';

    abstract public function getTechnicalName(): string;

    /**
     * @param array<string, mixed> $renderContext
     *
     * @return array<string, mixed>
     */
    final public function extendRenderContext(
        ProductExportEntity $productExport,
        SalesChannelContext $salesChannelContext,
        array $renderContext,
    ): array {
        $agenticConfig = $this->readSalesChannelConfiguration($productExport);

        $renderContext['provider'] = new ArrayStruct(array_merge(
            [
                'name' => $this->getTechnicalName(),
                self::REFERRING_SALES_CHANNEL_QUERY_PARAM => $productExport->getSalesChannelId(),
                OrderService::AFFILIATE_CODE_KEY => $agenticConfig[OrderService::AFFILIATE_CODE_KEY] ?? null,
                OrderService::CAMPAIGN_CODE_KEY => $agenticConfig[OrderService::CAMPAIGN_CODE_KEY] ?? null,
            ],
            $this->buildProviderContext($productExport, $salesChannelContext),
        ));

        return $renderContext;
    }

    /**
     * Return provider-specific render context fields. The base class adds common fields
     * (name, referringSalesChannel, affiliateCode, campaignCode) automatically.
     *
     * @return array<string, mixed>
     */
    abstract protected function buildProviderContext(
        ProductExportEntity $productExport,
        SalesChannelContext $salesChannelContext,
    ): array;

    /** @return array<string, mixed> */
    private function readSalesChannelConfiguration(ProductExportEntity $productExport): array
    {
        if (!$productExport->has('salesChannel')) {
            return [];
        }

        $salesChannel = $productExport->get('salesChannel');

        if (!$salesChannel instanceof SalesChannelEntity) {
            return [];
        }

        return $salesChannel->getConfiguration() ?? [];
    }
}
