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

        foreach ($methods as $methodIndex => $method) {
            if (!\is_array($method)) {
                continue;
            }

            $selected = $this->selectedDestination($method);
            if (null === $selected) {
                continue;
            }

            [$destinationIndex, $destination] = $selected;

            // A retail location keeps the postal address one level down; a shipping
            // destination is the postal address.
            $nested = \is_array($destination['address'] ?? null);
            $address = $nested ? $destination['address'] : $destination;

            $path = \sprintf('$.fulfillment.methods[%s].destinations[%s]', (string) $methodIndex, (string) $destinationIndex);

            $normalized = $this->fromPostalAddress($address, $nested ? $path.'.address' : $path);
            if (null !== $normalized) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * The destination the agent selected, or the first one offered, with its index.
     *
     * The index is carried so a violation can name the element that was actually read
     * rather than assuming `[0]`.
     *
     * @param array<string, mixed> $method
     *
     * @return array{0: array-key, 1: array<string, mixed>}|null
     */
    private function selectedDestination(array $method): ?array
    {
        $destinations = $method['destinations'] ?? null;
        if (!\is_array($destinations)) {
            return null;
        }

        $selectedId = $this->stringValue($method['selected_destination_id'] ?? null);
        $first = null;

        foreach ($destinations as $index => $destination) {
            if (!\is_array($destination)) {
                continue;
            }

            if (null !== $selectedId && $selectedId === $this->stringValue($destination['id'] ?? null)) {
                return [$index, $destination];
            }

            $first ??= [$index, $destination];
        }

        return $first;
    }

    /**
     * A `postal_address`, or a loud failure when one was attempted and got it wrong.
     *
     * The distinction that matters is **attempted** versus **absent**. A destination
     * carrying only an `id` is not a broken address: `shipping_destination` requires
     * `id` and nothing else, so selecting a destination the business already offered
     * looks exactly like that, and it must fall through to the stored session address.
     * But a destination carrying *some* postal field and not the rest is an agent
     * getting the shape wrong, and returning null there is what made the plugin
     * silently drop it and refuse two steps later with a message about a different
     * field — the defect that cost a day, reported from the other side in #131.
     *
     * So: any mapped field present ⇒ the agent meant to supply an address ⇒ say what is
     * missing, at the path it was read from.
     *
     * @param array<string, mixed> $address
     *
     * @return array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null
     */
    private function fromPostalAddress(array $address, string $path): ?array
    {
        // schema.org names, which is what `types/postal_address.json` uses — not
        // Shopware's street/zipcode/city and not line_one/city/country.
        $street = $this->stringValue($address['street_address'] ?? null);
        $zipcode = $this->stringValue($address['postal_code'] ?? null);
        $city = $this->stringValue($address['address_locality'] ?? null);

        if (null === $street || null === $zipcode || null === $city) {
            $this->rejectPartialAddress($address, $path, [
                'street_address' => $street,
                'postal_code' => $zipcode,
                'address_locality' => $city,
            ]);

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
     * Throws when an address was attempted, returns when none was.
     *
     * `postal_address` marks nothing required, so an incomplete one is schema-valid and
     * only this layer can catch it. Every other field of the address counts as evidence
     * of intent too — a destination naming a `postal_code` and an `address_region` and
     * no street is unambiguously a botched address, not a selection by id.
     *
     * @param array<string, mixed>   $address
     * @param array<string, ?string> $mapped  the fields this class reads, null where absent
     */
    private function rejectPartialAddress(array $address, string $path, array $mapped): void
    {
        $evidence = array_filter($mapped, static fn (?string $value): bool => null !== $value);
        if ([] === $evidence) {
            // Also treat the optional postal fields as intent, so a nearly-complete
            // address is reported rather than dropped.
            foreach (['extended_address', 'address_region', 'address_country'] as $field) {
                if (null !== $this->stringValue($address[$field] ?? null)) {
                    $evidence[$field] = $field;
                    break;
                }
            }
        }

        if ([] === $evidence) {
            return;
        }

        $violations = [];
        foreach ($mapped as $field => $value) {
            if (null === $value) {
                $violations[] = $path.'.'.$field.' is required';
            }
        }

        throw new ValidationException(\sprintf('Incomplete address at %s. A UCP postal address needs street_address, postal_code and address_locality, plus address_country to resolve the country.', $path), $violations);
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
            // This key exists for nothing but an address, so its mere presence is the
            // intent — no field-level evidence needed as with a destination.
            throw new ValidationException('Incomplete address at $.fulfillment.shipping_address. Prefer fulfillment.methods[].destinations[], which is where UCP puts the address; this shape needs street, zipcode and city.', array_values(array_filter([null === $street ? '$.fulfillment.shipping_address.street is required' : null, null === $zipcode ? '$.fulfillment.shipping_address.zipcode is required' : null, null === $city ? '$.fulfillment.shipping_address.city is required' : null])));
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
