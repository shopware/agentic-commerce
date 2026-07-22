<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\FulfillmentSelection;

/** @internal */
final class CheckoutGuestAddressPayloadResolver
{
    public function __construct(
        private readonly CheckoutSessionStore $sessionStore,
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
            $payload = $this->extractAddress($fulfillment->extra);
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
     * Reads a postal address from a fulfillment `extra` bag. Returns null when no
     * address object is present (so the caller can fall back to session metadata),
     * but throws loudly when an address object IS present yet incomplete/malformed -
     * instead of silently dropping it and surfacing a misleading "missing" error later.
     * Accepts the address under `shipping_address` or `billing_address` (the address is
     * used as the guest billing address) and tolerates standard field-name aliases.
     *
     * @param array<string, mixed> $extra
     *
     * @return array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null
     */
    private function extractAddress(array $extra): ?array
    {
        $payload = $extra;
        if (isset($payload['extra']) && \is_array($payload['extra'])) {
            $payload = $payload['extra'];
        }

        $address = $payload['shipping_address'] ?? $payload['billing_address'] ?? null;
        if (null === $address) {
            return null;
        }

        if (!\is_array($address)) {
            throw new ValidationException('fulfillment address must be an object with street (or line1), zipcode (or postal_code), city (or locality) and country_code (or country).', ['$.checkout_session.fulfillment.extra.shipping_address must be an object']);
        }

        $street = $this->stringValue($address['street'] ?? $address['street_address'] ?? $address['line1'] ?? $address['line_1'] ?? null);
        $zipcode = $this->stringValue($address['zipcode'] ?? $address['postal_code'] ?? $address['postalCode'] ?? $address['zip'] ?? null);
        $city = $this->stringValue($address['city'] ?? $address['locality'] ?? $address['address_locality'] ?? null);

        $missing = [];
        if (null === $street) {
            $missing[] = '$.checkout_session.fulfillment.shipping_address.street';
        }
        if (null === $zipcode) {
            $missing[] = '$.checkout_session.fulfillment.shipping_address.zipcode';
        }
        if (null === $city) {
            $missing[] = '$.checkout_session.fulfillment.shipping_address.city';
        }

        if (null === $street || null === $zipcode || null === $city) {
            throw new ValidationException('fulfillment address is incomplete. Provide fulfillment.extra.shipping_address (or billing_address) with: street (or street_address / line1), zipcode (or postal_code), city (or address_locality / locality), and country_code (or country).', $missing);
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

        $countryCode = $this->stringValue($address['country_code'] ?? $address['countryCode'] ?? $address['country'] ?? null);
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
