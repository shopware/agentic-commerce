<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Customer;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Exception\ValidationException;

/** @internal */
final class GuestCustomerAddressResolver
{
    public function __construct(
        private readonly AbstractCountryRoute $countryRoute,
    ) {
    }

    /**
     * @param array<string, mixed>|null $guestAddress
     *
     * @return array{street: string, zipcode: string, city: string, countryId: string}
     */
    public function resolve(SalesChannelContext $context, ?array $guestAddress): array
    {
        // The paths below name the property the agent can actually set. They used to
        // name `fulfillment.shipping_address`, which is not a property of
        // checkout.create, checkout.update or checkout.complete in any UCP version —
        // so the one error that says what is missing pointed at a field nothing could
        // fill, and reading it as a schema defect cost a day.
        if (null === $guestAddress) {
            throw new ValidationException('Checkout session has no shipping address; set fulfillment.methods[].destinations[] on checkout create or update before completion.', ['$.fulfillment.methods[0].destinations[0].street_address is required']);
        }

        $missingFields = [];
        foreach (['street' => 'street_address', 'zipcode' => 'postal_code', 'city' => 'address_locality'] as $field => $property) {
            if (!isset($guestAddress[$field]) || !\is_string($guestAddress[$field]) || '' === $guestAddress[$field]) {
                $missingFields[] = '$.fulfillment.methods[0].destinations[0].'.$property;
            }
        }

        if (!isset($guestAddress['countryId']) && !isset($guestAddress['countryCode'])) {
            $missingFields[] = '$.fulfillment.methods[0].destinations[0].address_country';
        }

        if ([] !== $missingFields) {
            throw new ValidationException('Checkout session has an incomplete shipping address; set a complete destination before completion.', $missingFields);
        }

        return [
            'street' => (string) $guestAddress['street'],
            'zipcode' => (string) $guestAddress['zipcode'],
            'city' => (string) $guestAddress['city'],
            'countryId' => $this->resolveCountryId($context, $guestAddress),
        ];
    }

    /**
     * @param array<string, mixed> $guestAddress
     */
    private function resolveCountryId(SalesChannelContext $context, array $guestAddress): string
    {
        if (isset($guestAddress['countryId']) && \is_string($guestAddress['countryId']) && Uuid::isValid($guestAddress['countryId'])) {
            return $guestAddress['countryId'];
        }

        $countryCode = strtoupper((string) ($guestAddress['countryCode'] ?? ''));
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', $countryCode));
        $criteria->setLimit(1);

        $country = $this->countryRoute->load(new Request(), $criteria, $context)->getCountries()->first();
        if (null === $country) {
            throw new ValidationException(\sprintf('Unknown country code "%s" stored on the checkout session.', $countryCode), ['$.fulfillment.methods[0].destinations[0].address_country is invalid']);
        }

        /** @var string $countryId */
        $countryId = $country->getUniqueIdentifier();

        return $countryId;
    }
}
