<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Common\Buyer;

final readonly class CheckoutSessionManager
{
    public function __construct(
        private CheckoutSessionStore $sessionStore,
    ) {
    }

    /**
     * @param list<string>                                                                                        $discountCodes
     * @param array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null $guestAddress
     */
    public function save(
        SalesChannelContext $salesChannelContext,
        string $status,
        ?Buyer $buyer,
        array $discountCodes = [],
        ?string $orderId = null,
        ?array $guestAddress = null,
    ): void {
        $metadata = [
            'status' => $status,
            'buyer' => $this->buyerPayload($buyer),
        ];

        if ([] !== $discountCodes) {
            $metadata['discounts'] = array_map(
                static fn (string $discountCode): array => ['code' => $discountCode],
                $discountCodes,
            );
        }

        if (null !== $orderId) {
            $metadata['orderId'] = $orderId;
        }

        if (null !== $guestAddress) {
            $metadata['guestAddress'] = $guestAddress;
        }

        $this->sessionStore->save($salesChannelContext, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function buyer(array $metadata): ?Buyer
    {
        return $this->sessionStore->buyer($metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null
     */
    public function guestAddress(array $metadata): ?array
    {
        return $this->sessionStore->guestAddress($metadata);
    }

    /**
     * @return array{email: ?string, firstName: ?string, lastName: ?string, phoneNumber: ?string}|null
     */
    private function buyerPayload(?Buyer $buyer): ?array
    {
        if (null === $buyer) {
            return null;
        }

        return [
            'email' => $buyer->email,
            'firstName' => $buyer->firstName,
            'lastName' => $buyer->lastName,
            'phoneNumber' => $buyer->phoneNumber,
        ];
    }
}
