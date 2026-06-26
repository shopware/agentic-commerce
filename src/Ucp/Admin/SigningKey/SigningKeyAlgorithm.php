<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Admin\SigningKey;

use Shopware\Core\Framework\Log\Package;

/**
 * Signing key algorithms the SDK's key manager can actually generate.
 */
#[Package('framework')]
enum SigningKeyAlgorithm: string
{
    case ES256 = 'ES256';
    case ES384 = 'ES384';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
