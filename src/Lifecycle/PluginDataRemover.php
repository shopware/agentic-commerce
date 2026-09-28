<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Lifecycle;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\SwagAgenticCommerce;
use Swag\AgenticCommerce\Ucp\Checkout\DoctrineDbalCheckoutCompletionStore;
use Swag\AgenticCommerce\Ucp\Config\DoctrineDbalUcpConfigRepository;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Identity\DoctrineDbalUcpOAuthStore;
use Swag\AgenticCommerce\Ucp\Onboarding\OnboardingDismissal;

/**
 * Removes what this plugin owns when a merchant uninstalls without keeping data.
 *
 * Deliberately narrow. Several things the plugin creates are shared with, or
 * depended on by, something that outlives it, and dropping them would break a
 * shop rather than clean it:
 *
 * - `sales_channel_tracking_order` / `_customer` ship natively in Shopware
 *   6.7.10+; the plugin's migration only creates them where core has not.
 * - The Agentic Commerce `sales_channel_type` row shares its UUID with core, so
 *   a merchant's feed channels survive the plugin being removed. Dropping it
 *   would orphan them.
 * - `product_export.provider` is a column on a core table.
 * - The `ucp_*` tables belong to the SDK bundle, which another consumer could
 *   in principle also install.
 *
 * What is left behind is therefore inert: rows in tables that something else
 * owns. What is removed is everything that carries this plugin's own prefix,
 * plus the per-user onboarding flag it writes into core's `user_config`.
 *
 * @internal
 */
#[Package('framework')]
final class PluginDataRemover
{
    /**
     * @var list<string>
     */
    public const PLUGIN_TABLES = [
        DoctrineDbalUcpOAuthStore::ACCESS_TOKEN_TABLE,
        DoctrineDbalUcpOAuthStore::REFRESH_TOKEN_TABLE,
        DoctrineDbalUcpOAuthStore::CODE_TABLE,
        DoctrineDbalCheckoutCompletionStore::TABLE,
        DoctrineDbalUcpConfigRepository::TABLE,
    ];

    /**
     * @var list<string>
     */
    public const CONFIG_DOMAINS = [
        UcpConfigService::DOMAIN,
        SwagAgenticCommerce::OPEN_AI_PRODUCT_EXPORT_CONFIG_DOMAIN,
        SwagAgenticCommerce::GOOGLE_PRODUCT_EXPORT_CONFIG_DOMAIN,
    ];

    public static function removeAll(Connection $connection): void
    {
        foreach (self::PLUGIN_TABLES as $table) {
            $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }

        foreach (self::CONFIG_DOMAINS as $domain) {
            $connection->executeStatement(
                'DELETE FROM `system_config` WHERE `configuration_key` LIKE :domain',
                ['domain' => $domain.'%'],
            );
        }

        OnboardingDismissal::reset($connection);
    }
}
