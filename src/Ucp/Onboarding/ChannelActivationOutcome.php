<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\Validation\Finding;

/**
 * What happened to one sales channel during a bulk activation.
 *
 * A batch reports an outcome per channel rather than failing as a whole: one
 * refused channel says nothing about the others, and the merchant needs to see
 * which of their channels came up.
 *
 * @internal
 */
#[Package('framework')]
final class ChannelActivationOutcome implements \JsonSerializable
{
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public const REASON_ALREADY_ACTIVE = 'already_active';
    public const REASON_NOT_FOUND = 'not_found';

    /**
     * @param list<Finding> $findings readiness findings from UcpConfigValidator, empty unless enabled
     */
    private function __construct(
        public readonly string $salesChannelId,
        public readonly ?string $salesChannelName,
        public readonly string $status,
        public readonly array $findings = [],
        public readonly ?string $reason = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * @param list<Finding> $findings
     */
    public static function enabled(string $salesChannelId, ?string $salesChannelName, array $findings): self
    {
        return new self($salesChannelId, $salesChannelName, self::STATUS_ENABLED, $findings);
    }

    public static function skipped(string $salesChannelId, ?string $salesChannelName, string $reason): self
    {
        return new self($salesChannelId, $salesChannelName, self::STATUS_SKIPPED, [], $reason);
    }

    public static function failed(string $salesChannelId, ?string $salesChannelName, string $code, string $message): self
    {
        return new self($salesChannelId, $salesChannelName, self::STATUS_FAILED, [], null, $code, $message);
    }

    public function isEnabled(): bool
    {
        return self::STATUS_ENABLED === $this->status;
    }

    public function isSkipped(): bool
    {
        return self::STATUS_SKIPPED === $this->status;
    }

    public function isFailed(): bool
    {
        return self::STATUS_FAILED === $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'salesChannelId' => $this->salesChannelId,
            'salesChannelName' => $this->salesChannelName,
            'status' => $this->status,
            'findings' => array_map(static fn (Finding $finding): array => $finding->toArray(), $this->findings),
        ];

        foreach (['reason' => $this->reason, 'code' => $this->code, 'message' => $this->message] as $key => $value) {
            if (null !== $value) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
