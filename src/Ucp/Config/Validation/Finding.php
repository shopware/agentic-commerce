<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config\Validation;

use Shopware\Core\Framework\Log\Package;

/**
 * A single UCP configuration health finding for one sales channel.
 *
 * @internal
 */
#[Package('framework')]
final class Finding
{
    public function __construct(
        public readonly string $salesChannelId,
        public readonly string $salesChannelName,
        public readonly Severity $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $remediation = null,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            ...get_object_vars($this),
            'severity' => strtolower($this->severity->name),
        ];
    }
}
