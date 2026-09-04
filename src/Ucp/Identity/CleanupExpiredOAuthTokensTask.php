<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Identity;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/** @internal */
#[Package('framework')]
class CleanupExpiredOAuthTokensTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'swag_agentic_commerce.ucp_oauth_token.cleanup';
    }

    public static function getDefaultInterval(): int
    {
        // 86400 = one day. Use the literal rather than self::DAILY: the ScheduledTask
        // interval constants are not defined on Shopware 6.5.
        return 86400;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
