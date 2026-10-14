<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Readiness;

use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
#[Package('discovery')]
final class BasicAiReadinessException extends HttpException
{
    public const SALES_CHANNEL_NOT_ELIGIBLE = 'SWAG_AGENTIC_COMMERCE__READINESS_SALES_CHANNEL_NOT_ELIGIBLE';
    public const INVALID_PAYLOAD = 'SWAG_AGENTIC_COMMERCE__READINESS_INVALID_PAYLOAD';

    /**
     * @param list<string> $salesChannelIds
     */
    public static function salesChannelsNotEligible(array $salesChannelIds): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::SALES_CHANNEL_NOT_ELIGIBLE,
            'Basic AI Readiness applies to Storefront and Headless sales channels only. Unknown or not eligible: {{ salesChannelIds }}.',
            ['salesChannelIds' => implode(', ', $salesChannelIds)],
        );
    }

    public static function invalidPayload(string $reason): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_PAYLOAD,
            'Invalid Basic AI Readiness request: {{ reason }}',
            ['reason' => $reason],
        );
    }
}
