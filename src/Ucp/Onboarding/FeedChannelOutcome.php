<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Log\Package;

/**
 * What happened to one requested (storefront, provider) feed channel. Like
 * {@see ChannelActivationOutcome}, one failure never fails the batch.
 *
 * @internal
 */
#[Package('framework')]
final class FeedChannelOutcome implements \JsonSerializable
{
    public const STATUS_CREATED = 'created';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public const REASON_ALREADY_CONNECTED = 'already_connected';

    public const CODE_NOT_FOUND = 'not_found';
    public const CODE_NOT_A_STOREFRONT = 'not_a_storefront';
    public const CODE_NO_STOREFRONT_DOMAIN = 'no_storefront_domain';
    public const CODE_WRITE_FAILED = 'write_failed';

    private function __construct(
        public readonly string $storefrontSalesChannelId,
        public readonly ?string $storefrontSalesChannelName,
        public readonly FeedProvider $provider,
        public readonly string $status,
        public readonly ?string $salesChannelId = null,
        public readonly ?string $salesChannelName = null,
        public readonly ?string $feedUrl = null,
        public readonly ?string $reason = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function created(
        string $storefrontSalesChannelId,
        ?string $storefrontSalesChannelName,
        FeedProvider $provider,
        string $salesChannelId,
        string $salesChannelName,
        string $feedUrl,
    ): self {
        return new self($storefrontSalesChannelId, $storefrontSalesChannelName, $provider, self::STATUS_CREATED, $salesChannelId, $salesChannelName, $feedUrl);
    }

    public static function skipped(
        string $storefrontSalesChannelId,
        ?string $storefrontSalesChannelName,
        FeedChannelView $existing,
    ): self {
        return new self(
            $storefrontSalesChannelId,
            $storefrontSalesChannelName,
            $existing->provider,
            self::STATUS_SKIPPED,
            $existing->salesChannelId,
            $existing->salesChannelName,
            $existing->feedUrl,
            self::REASON_ALREADY_CONNECTED,
        );
    }

    public static function failed(
        string $storefrontSalesChannelId,
        ?string $storefrontSalesChannelName,
        FeedProvider $provider,
        string $code,
        string $message,
    ): self {
        return new self($storefrontSalesChannelId, $storefrontSalesChannelName, $provider, self::STATUS_FAILED, code: $code, message: $message);
    }

    public function isCreated(): bool
    {
        return self::STATUS_CREATED === $this->status;
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
            'storefrontSalesChannelId' => $this->storefrontSalesChannelId,
            'storefrontSalesChannelName' => $this->storefrontSalesChannelName,
            'provider' => $this->provider->value,
            'status' => $this->status,
        ];

        $optional = [
            'reason' => $this->reason,
            'salesChannelId' => $this->salesChannelId,
            'salesChannelName' => $this->salesChannelName,
            'feedUrl' => $this->feedUrl,
            'code' => $this->code,
            'message' => $this->message,
        ];
        foreach ($optional as $key => $value) {
            if (null !== $value) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
