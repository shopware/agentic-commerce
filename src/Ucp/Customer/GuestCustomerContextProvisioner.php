<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Customer;

use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Common\Buyer;

final class GuestCustomerContextProvisioner implements GuestCustomerContextProvisionerInterface
{
    public function __construct(
        private readonly AbstractRegisterRoute $registerRoute,
        private readonly SalesChannelContextServiceInterface $contextService,
        private readonly GuestCustomerAddressResolver $addressResolver,
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
            throw new ValidationException('Checkout session is missing buyer.email; set it on checkout create or update before completion.', ['$.checkout_session.buyer.email is required']);
        }

        $address = $this->addressResolver->resolve($context, $guestAddress);
        $firstName = $buyer->firstName ?: 'Guest';
        $lastName = $buyer->lastName ?: 'Customer';

        $response = $this->registerRoute->register(new RequestDataBag([
            'guest' => true,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $buyer->email,
            'billingAddress' => [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'street' => $address['street'],
                'zipcode' => $address['zipcode'],
                'city' => $address['city'],
                'countryId' => $address['countryId'],
                'phoneNumber' => $buyer->phoneNumber,
            ],
        ]), $context, false);

        $customer = $response->getCustomer();
        $newToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN);
        if (!\is_string($newToken) || '' === $newToken) {
            throw new ValidationException('Guest customer registration did not return a Shopware context token.', ['$.checkout_session.context_token is required']);
        }

        return $this->contextService->get(new SalesChannelContextServiceParameters(
            $context->getSalesChannelId(),
            $newToken,
            $context->getLanguageId(),
            $context->getCurrencyId(),
            $context->getDomainId(),
            null,
            $customer->getId(),
        ));
    }
}
