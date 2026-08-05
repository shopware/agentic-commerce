<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

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

        return $this->fromDestinations($payload) ?? $this->fromShippingAddress($payload);
    }

    /**
     * The address where UCP actually puts it.
     *
     * `fulfillment.methods[].destinations[]` is the only address channel the protocol
     * has — there is no `fulfillment.shipping_address` in `checkout.create`,
     * `checkout.update` or `checkout.complete`. Reading only the latter meant a
     * conformant agent could set no address at all, so completion always refused and
     * no order could be placed.
     *
     * A destination is a oneOf: a `shipping_destination` carries the postal address
     * inline next to its `id`, a `retail_location` nests it under `address`. Both are
     * accepted; the schema makes only the first satisfiable in practice (an object with
     * `id` AND `name` matches both branches and is therefore rejected), but a pickup
     * location that names an address is still unambiguous to read.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null
     */
    private function fromDestinations(array $payload): ?array
    {
        $methods = $payload['methods'] ?? null;
        if (!\is_array($methods)) {
            return null;
        }

        foreach ($methods as $method) {
            if (!\is_array($method)) {
                continue;
            }

            $destination = $this->selectedDestination($method);
            if (null === $destination) {
                continue;
            }

            // A retail location keeps the postal address one level down; a shipping
            // destination is the postal address.
            $address = \is_array($destination['address'] ?? null) ? $destination['address'] : $destination;

            $normalized = $this->fromPostalAddress($address);
            if (null !== $normalized) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * The destination the agent selected, or the first one offered.
     *
     * @param array<string, mixed> $method
     *
     * @return array<string, mixed>|null
     */
    private function selectedDestination(array $method): ?array
    {
        $destinations = $method['destinations'] ?? null;
        if (!\is_array($destinations)) {
            return null;
        }

        $selectedId = $this->stringValue($method['selected_destination_id'] ?? null);
        $first = null;

        foreach ($destinations as $destination) {
            if (!\is_array($destination)) {
                continue;
            }

            if (null !== $selectedId && $selectedId === $this->stringValue($destination['id'] ?? null)) {
                return $destination;
            }

            $first ??= $destination;
        }

        return $first;
    }

    /**
     * @param array<string, mixed> $address
     *
     * @return array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null
     */
    private function fromPostalAddress(array $address): ?array
    {
        // schema.org names, which is what `types/postal_address.json` uses — not
        // Shopware's street/zipcode/city and not line_one/city/country.
        $street = $this->stringValue($address['street_address'] ?? null);
        $zipcode = $this->stringValue($address['postal_code'] ?? null);
        $city = $this->stringValue($address['address_locality'] ?? null);
        if (null === $street || null === $zipcode || null === $city) {
            return null;
        }

        $extended = $this->stringValue($address['extended_address'] ?? null);
        if (null !== $extended) {
            $street .= ' '.$extended;
        }

        $normalized = [
            'street' => $street,
            'zipcode' => $zipcode,
            'city' => $city,
        ];

        $countryCode = $this->stringValue($address['address_country'] ?? null);
        if (null !== $countryCode) {
            $normalized['countryCode'] = strtoupper($countryCode);
        }

        return $normalized;
    }

    /**
     * The Shopware-shaped address this class used to accept, kept as a fallback.
     *
     * Not a UCP field, so nothing conformant sends it, but it costs little and an
     * agent built against the old behaviour keeps working.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null
     */
    private function fromShippingAddress(array $payload): ?array
    {
        $address = $payload['shipping_address'] ?? null;
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
