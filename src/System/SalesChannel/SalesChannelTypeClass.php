<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\System\SalesChannel;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * An unknown type lands on {@see self::Other} and joins neither group, so every
 * feature gating on this fails closed.
 *
 * @internal
 */
#[Package('discovery')]
enum SalesChannelTypeClass
{
    case Storefront;
    case Headless;
    case ProductComparison;
    case AgenticCommerce;
    case Other;

    public static function forTypeId(string $typeId): self
    {
        return match ($typeId) {
            Defaults::SALES_CHANNEL_TYPE_STOREFRONT => self::Storefront,
            Defaults::SALES_CHANNEL_TYPE_API => self::Headless,
            Defaults::SALES_CHANNEL_TYPE_PRODUCT_COMPARISON => self::ProductComparison,
            SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE => self::AgenticCommerce,
            default => self::Other,
        };
    }

    public function isTransactional(): bool
    {
        return self::Storefront === $this || self::Headless === $this;
    }

    public function isProductExport(): bool
    {
        return self::ProductComparison === $this || self::AgenticCommerce === $this;
    }

    /**
     * @return list<string>
     */
    public static function transactionalTypeIds(): array
    {
        return [
            Defaults::SALES_CHANNEL_TYPE_STOREFRONT,
            Defaults::SALES_CHANNEL_TYPE_API,
        ];
    }
}
