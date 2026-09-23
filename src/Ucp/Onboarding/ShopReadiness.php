<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Log\Package;

/**
 * Whether this shop is ready for AI agents, and what is left to do.
 *
 * Two readings of "ready" coexist deliberately. `steps` is the merchant-facing
 * progress ("2 of 3 complete") and counts only what has been done. `status`
 * additionally consults the validator findings, because a sales channel can be
 * switched on and still be unreachable: no domain, no signing key, or nothing on
 * the platform allowlist. Reporting the step count alone would tell a merchant
 * they are ready when no agent can talk to them.
 *
 * @internal
 */
#[Package('framework')]
final class ShopReadiness implements \JsonSerializable
{
    public const STATUS_SETUP_NEEDED = 'setup_needed';
    public const STATUS_ACTION_NEEDED = 'action_needed';
    public const STATUS_READY = 'ready';

    /**
     * @param list<ChannelReadiness> $channels
     */
    public function __construct(
        public readonly string $status,
        public readonly int $preparedCount,
        public readonly int $agenticSalesChannelCount,
        public readonly array $channels,
    ) {
    }

    public function isPrepared(): bool
    {
        return $this->preparedCount > 0;
    }

    public function isConnected(): bool
    {
        return $this->agenticSalesChannelCount > 0;
    }

    public function completedSteps(): int
    {
        return 1 + ($this->isPrepared() ? 1 : 0) + ($this->isConnected() ? 1 : 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'completedSteps' => $this->completedSteps(),
            'totalSteps' => 3,
            'steps' => [
                // The extension answering this route is itself the proof of step one.
                'installed' => ['done' => true],
                'prepared' => ['done' => $this->isPrepared(), 'count' => $this->preparedCount],
                'connected' => ['done' => $this->isConnected(), 'count' => $this->agenticSalesChannelCount],
            ],
            'channels' => array_map(
                static fn (ChannelReadiness $channel): array => $channel->jsonSerialize(),
                $this->channels,
            ),
        ];
    }
}
