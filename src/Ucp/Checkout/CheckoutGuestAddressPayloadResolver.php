<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Ucp\Sdk\Model\Checkout\FulfillmentSelection;

final readonly class CheckoutGuestAddressPayloadResolver
{
    public function __construct(
        private CheckoutSessionStore $sessionStore,
    ) {
    }

    /**
     * @param array<string, mixed>|null $metadata
     *
     * @return array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null
     */
    public function resolve(?FulfillmentSelection $fulfillment, ?array $metadata = null): ?array
    {
        if (null !== $fulfillment) {
            $payload = $this->normalize($fulfillment->extra);
            if (null !== $payload) {
                return $payload;
            }
        }

        if (\is_array($metadata)) {
            return $this->sessionStore->guestAddress($metadata);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null
     */
    private function normalize(array $extra): ?array
    {
        $payload = $extra;
        if (isset($payload['extra']) && \is_array($payload['extra'])) {
            $payload = $payload['extra'];
        }

        $address = $payload['shipping_address'] ?? $payload['shippingAddress'] ?? null;
        if (!\is_array($address)) {
            return null;
        }

        $street = $this->stringValue($address['street'] ?? null);
        $zipcode = $this->stringValue($address['zipcode'] ?? null);
        $city = $this->stringValue($address['city'] ?? null);
        if (null === $street || null === $zipcode || null === $city) {
            return null;
        }

        $normalized = [
            'street' => $street,
            'zipcode' => $zipcode,
            'city' => $city,
        ];

        $countryId = $this->stringValue($address['country_id'] ?? $address['countryId'] ?? null);
        if (null !== $countryId) {
            $normalized['countryId'] = $countryId;
        }

        $countryCode = $this->stringValue($address['country_code'] ?? $address['countryCode'] ?? null);
        if (null !== $countryCode) {
            $normalized['countryCode'] = strtoupper($countryCode);
        }

        return $normalized;
    }

    private function stringValue(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
