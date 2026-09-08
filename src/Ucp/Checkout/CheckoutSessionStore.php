<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Common\Buyer;

/** @internal */
final class CheckoutSessionStore
{
    private const PAYLOAD_KEY = 'swagAgenticCommerce';
    private const CHECKOUT_KEY = 'ucpCheckout';
    private const CONTEXT_TOKEN_KEY = 'shopwareContextToken';

    public function __construct(
        private readonly SalesChannelContextPersister $persister,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $token, string $salesChannelId, ?string $customerId = null): array
    {
        $payload = $this->persister->load($token, $salesChannelId, $customerId);

        return \is_array($payload[self::PAYLOAD_KEY][self::CHECKOUT_KEY] ?? null)
            ? $payload[self::PAYLOAD_KEY][self::CHECKOUT_KEY]
            : [];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function save(SalesChannelContext $context, array $metadata): void
    {
        $this->saveForToken($context->getToken(), $context->getSalesChannelId(), $metadata, $context->getCustomer()?->getId());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function saveForToken(string $token, string $salesChannelId, array $metadata, ?string $customerId = null): void
    {
        $this->persister->save(
            $token,
            [
                self::PAYLOAD_KEY => [
                    self::CHECKOUT_KEY => $metadata,
                ],
            ],
            $salesChannelId,
            $customerId,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function contextToken(array $metadata, string $fallbackToken): string
    {
        return $this->storedContextToken($metadata) ?? $fallbackToken;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function storedContextToken(array $metadata): ?string
    {
        $token = $metadata[self::CONTEXT_TOKEN_KEY] ?? null;

        return \is_string($token) && '' !== $token ? $token : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function buyer(array $metadata): ?Buyer
    {
        $buyer = $metadata['buyer'] ?? null;
        if (!\is_array($buyer)) {
            return null;
        }

        return new Buyer(
            isset($buyer['email']) && \is_string($buyer['email']) ? $buyer['email'] : null,
            isset($buyer['firstName']) && \is_string($buyer['firstName']) ? $buyer['firstName'] : null,
            isset($buyer['lastName']) && \is_string($buyer['lastName']) ? $buyer['lastName'] : null,
            isset($buyer['phoneNumber']) && \is_string($buyer['phoneNumber']) ? $buyer['phoneNumber'] : null,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null
     */
    public function guestAddress(array $metadata): ?array
    {
        $guestAddress = $metadata['guestAddress'] ?? null;
        if (!\is_array($guestAddress)) {
            return null;
        }

        $street = $guestAddress['street'] ?? null;
        $zipcode = $guestAddress['zipcode'] ?? null;
        $city = $guestAddress['city'] ?? null;
        if (!\is_string($street) || !\is_string($zipcode) || !\is_string($city)) {
            return null;
        }

        $payload = [
            'street' => $street,
            'zipcode' => $zipcode,
            'city' => $city,
        ];

        if (isset($guestAddress['countryCode']) && \is_string($guestAddress['countryCode']) && '' !== $guestAddress['countryCode']) {
            $payload['countryCode'] = $guestAddress['countryCode'];
        }

        if (isset($guestAddress['countryId']) && \is_string($guestAddress['countryId']) && '' !== $guestAddress['countryId']) {
            $payload['countryId'] = $guestAddress['countryId'];
        }

        return $payload;
    }
}
