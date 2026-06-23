<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Admin\SigningKey;

use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Response;

#[Package('framework')]
final class UcpSigningKeyException extends HttpException
{
    public const INVALID_KID = 'SWAG_AGENTIC_COMMERCE__UCP_SIGNING_KEY_INVALID_KID';
    public const INVALID_ALGORITHM = 'SWAG_AGENTIC_COMMERCE__UCP_SIGNING_KEY_INVALID_ALGORITHM';

    public static function invalidKid(string $message): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_KID,
            \sprintf('Invalid signing key id: %s.', $message),
        );
    }

    /**
     * @param list<string> $allowed
     */
    public static function invalidAlgorithm(string $algorithm, array $allowed): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::INVALID_ALGORITHM,
            \sprintf('Unsupported signing key algorithm "%s". Allowed values: %s.', $algorithm, implode(', ', $allowed)),
            ['algorithm' => $algorithm],
        );
    }
}
