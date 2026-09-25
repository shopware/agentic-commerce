<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;

/**
 * The per-admin-user "do not offer the onboarding dialog again" flag.
 *
 * It lives in core's `user_config` because that is where the Administration can
 * write per-user state, which means a plugin uninstall does not remove it: a
 * merchant who dismissed the dialog once would never be offered it again, not
 * even after reinstalling the plugin. Installing or activating therefore clears
 * it, so a fresh install always offers onboarding.
 *
 * @internal
 */
#[Package('framework')]
final class OnboardingDismissal
{
    /**
     * Must match ONBOARDING_DISMISSAL_KEY in
     * Resources/app/administration/src/extension/sw-sales-channel/agentic-commerce/onboarding-state.js.
     * OnboardingDismissalKeyTest holds the two together.
     */
    public const USER_CONFIG_KEY = 'swag-agentic-commerce.onboarding.dismissed';

    public static function reset(Connection $connection): void
    {
        try {
            $connection->executeStatement(
                'DELETE FROM `user_config` WHERE `key` = :key',
                ['key' => self::USER_CONFIG_KEY],
            );
        } catch (\Throwable) {
            // Never fail an install over a dialog preference. The worst case is
            // that an admin who dismissed the offer does not see it again.
        }
    }
}
