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
        public readonly string $channelName,
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
            'salesChannelId' => $this->salesChannelId,
            'salesChannelName' => $this->channelName,
            'severity' => $this->severity->value,
            'code' => $this->code,
            'message' => $this->message,
            'remediation' => $this->remediation,
        ];
    }
}
