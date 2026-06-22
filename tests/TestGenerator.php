<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\Generator;

/**
 * Cross-version shim for Shopware's test Generator utility.
 *
 * Shopware 6.5 ships `Generator::createSalesChannelContext()`.
 * Shopware 6.6+ renamed it to `Generator::generateSalesChannelContext()`.
 * Test code targeting both versions must go through this wrapper.
 */
final class TestGenerator
{
    public static function generateSalesChannelContext(
        ?Context $baseContext = null,
        ?SalesChannelEntity $salesChannel = null,
        ?CountryEntity $country = null,
    ): SalesChannelContext {
        if (method_exists(Generator::class, 'generateSalesChannelContext')) {
            return Generator::generateSalesChannelContext(
                baseContext: $baseContext,
                salesChannel: $salesChannel,
                country: $country,
            );
        }

        // @phpstan-ignore-next-line staticMethod.notFound -- dead branch on 6.7+ where createSalesChannelContext() was removed
        return Generator::createSalesChannelContext(
            baseContext: $baseContext,
            salesChannel: $salesChannel,
            country: $country,
        );
    }
}
