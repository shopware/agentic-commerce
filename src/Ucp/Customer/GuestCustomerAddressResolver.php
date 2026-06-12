<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Customer;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ValidationException;

final class GuestCustomerAddressResolver
{
    /**
     * @param EntityRepository<CountryCollection> $countryRepository
     */
    public function __construct(
        private readonly EntityRepository $countryRepository,
    ) {
    }

    /**
     * @param array<string, mixed>|null $guestAddress
     *
     * @return array{street: string, zipcode: string, city: string, countryId: string}
     */
    public function resolve(SalesChannelContext $context, ?array $guestAddress): array
    {
        if (null === $guestAddress) {
            throw new ValidationException('Checkout session is missing fulfillment.shipping_address; set it on checkout create or update before completion.', ['$.checkout_session.fulfillment.shipping_address is required']);
        }

        $missingFields = [];
        foreach (['street', 'zipcode', 'city'] as $field) {
            if (!isset($guestAddress[$field]) || !\is_string($guestAddress[$field]) || '' === $guestAddress[$field]) {
                $missingFields[] = '$.checkout_session.fulfillment.shipping_address.'.$field;
            }
        }

        if (!isset($guestAddress['countryId']) && !isset($guestAddress['countryCode'])) {
            $missingFields[] = '$.checkout_session.fulfillment.shipping_address.country_code';
        }

        if ([] !== $missingFields) {
            throw new ValidationException('Checkout session has an incomplete fulfillment.shipping_address; set a complete address before completion.', $missingFields);
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

        $country = $this->countryRepository->search($criteria, $context->getContext())->first();
        if (null === $country) {
            throw new ValidationException(\sprintf('Unknown country code "%s" stored on the checkout session.', $countryCode), ['$.checkout_session.fulfillment.shipping_address.country_code is invalid']);
        }

        /** @var string $countryId */
        $countryId = $country->getUniqueIdentifier();

        return $countryId;
    }
}
