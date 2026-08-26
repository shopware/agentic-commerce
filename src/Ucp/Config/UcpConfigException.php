<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
#[Package('framework')]
final class UcpConfigException extends HttpException
{
    public const INVALID_CONFIG = 'SWAG_AGENTIC_COMMERCE__UCP_CONFIG_INVALID';
    public const INVALID_JSON = 'SWAG_AGENTIC_COMMERCE__UCP_CONFIG_INVALID_JSON';
    public const SALES_CHANNEL_TYPE_NOT_SUPPORTED = 'SWAG_AGENTIC_COMMERCE__UCP_SALES_CHANNEL_TYPE_NOT_SUPPORTED';

    public static function invalidValue(string $path, string $message): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_CONFIG,
            \sprintf('Invalid UCP config at %s: %s.', $path, $message),
            ['path' => $path],
        );
    }

    public static function salesChannelTypeCannotBeActivated(string $salesChannelId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::SALES_CHANNEL_TYPE_NOT_SUPPORTED,
            'UCP cannot be activated for sales channel "{{ salesChannelId }}": its type cannot complete a purchase. Use a storefront or headless sales channel.',
            ['salesChannelId' => $salesChannelId],
        );
    }

    public static function invalidJsonPayload(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_JSON,
            'Invalid JSON payload.',
        );
    }
}
