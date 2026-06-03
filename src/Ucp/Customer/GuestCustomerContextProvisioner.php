<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Customer;

use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalutationCollection;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Common\Buyer;

final readonly class GuestCustomerContextProvisioner
{
    /**
     * @param EntityRepository<CustomerCollection>   $customerRepository
     * @param EntityRepository<SalutationCollection> $salutationRepository
     */
    public function __construct(
        private EntityRepository $customerRepository,
        private EntityRepository $salutationRepository,
        private NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private SalesChannelContextPersister $persister,
        private SalesChannelContextServiceInterface $contextService,
        private GuestCustomerAddressResolver $addressResolver,
    ) {
    }

    /**
     * @param array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null $guestAddress
     */
    public function ensureGuestCustomer(SalesChannelContext $context, ?Buyer $buyer, ?array $guestAddress = null): SalesChannelContext
    {
        if (null !== $context->getCustomer()) {
            return $context;
        }

        if (null === $buyer || null === $buyer->email || '' === $buyer->email) {
            throw new ValidationException('Checkout completion requires buyer.email for guest order creation.', ['$.buyer.email is required']);
        }

        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();
        $salutationId = $this->defaultSalutationId($context);
        $address = $this->addressResolver->resolve($context, $guestAddress);

        $firstName = $buyer->firstName ?: 'Guest';
        $lastName = $buyer->lastName ?: 'Customer';

        $customer = [
            'id' => $customerId,
            'customerNumber' => $this->numberRangeValueGenerator->getValue(
                $this->customerRepository->getDefinition()->getEntityName(),
                $context->getContext(),
                $context->getSalesChannelId(),
            ),
            'salesChannelId' => $context->getSalesChannelId(),
            'languageId' => $context->getLanguageId(),
            'groupId' => $this->customerGroupId($context),
            'salutationId' => $salutationId,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $buyer->email,
            'active' => true,
            'guest' => true,
            'firstLogin' => new \DateTimeImmutable(),
            'defaultPaymentMethodId' => $context->getPaymentMethod()->getId(),
            'defaultBillingAddressId' => $addressId,
            'defaultShippingAddressId' => $addressId,
            'addresses' => [[
                'id' => $addressId,
                'customerId' => $customerId,
                'salutationId' => $salutationId,
                'firstName' => $firstName,
                'lastName' => $lastName,
                'street' => $address['street'],
                'zipcode' => $address['zipcode'],
                'city' => $address['city'],
                'countryId' => $address['countryId'],
                'phoneNumber' => $buyer->phoneNumber,
            ]],
        ];

        $this->customerRepository->create([$customer], $context->getContext());

        $this->persister->save(
            $context->getToken(),
            [
                'customerId' => $customerId,
                'billingAddressId' => $addressId,
                'shippingAddressId' => $addressId,
                'paymentMethodId' => $context->getPaymentMethod()->getId(),
                'shippingMethodId' => $context->getShippingMethod()->getId(),
                'languageId' => $context->getLanguageId(),
                'currencyId' => $context->getCurrencyId(),
                'domainId' => $context->getDomainId(),
            ],
            $context->getSalesChannelId(),
            $customerId,
        );

        return $this->contextService->get(new SalesChannelContextServiceParameters(
            $context->getSalesChannelId(),
            $context->getToken(),
            $context->getLanguageId(),
            $context->getCurrencyId(),
            $context->getDomainId(),
            null,
            $customerId,
        ));
    }

    private function defaultSalutationId(SalesChannelContext $context): string
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);

        $salutation = $this->salutationRepository->search($criteria, $context->getContext())->first();
        if (null === $salutation) {
            throw new \RuntimeException('No salutation is available for guest customer creation.');
        }

        return $salutation->getId();
    }

    private function customerGroupId(SalesChannelContext $context): string
    {
        // Shopware 6.6+ exposes the current group directly on the sales-channel context.
        // 6.5 still requires reading the hydrated customer-group entity instead.
        if (method_exists($context, 'getCustomerGroupId')) {
            /** @var string $customerGroupId */
            $customerGroupId = $context->getCustomerGroupId();

            return $customerGroupId;
        }

        return $context->getCurrentCustomerGroup()->getId();
    }
}
