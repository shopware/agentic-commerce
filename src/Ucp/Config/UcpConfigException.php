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

    public static function invalidValue(string $path, string $message): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_CONFIG,
            \sprintf('Invalid UCP config at %s: %s.', $path, $message),
            ['path' => $path],
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
