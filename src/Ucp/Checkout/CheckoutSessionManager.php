<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Common\Buyer;

/** @internal */
final class CheckoutSessionManager implements CheckoutSessionManagerInterface
{
    public function __construct(
        private readonly CheckoutSessionStore $sessionStore,
    ) {
    }

    /**
     * @param list<string>                                                                                        $discountCodes
     * @param array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null $guestAddress
     * @param array<string, mixed>|null                                                                           $selectedPayment
     */
    public function save(
        SalesChannelContext $salesChannelContext,
        string $status,
        ?Buyer $buyer,
        array $discountCodes = [],
        ?string $orderId = null,
        ?string $orderDeepLinkCode = null,
        ?array $guestAddress = null,
        ?array $selectedPayment = null,
        bool $ap2Locked = false,
    ): void {
        $metadata = $this->metadata($salesChannelContext, $status, $buyer, $discountCodes, $orderId, $orderDeepLinkCode, $guestAddress, $selectedPayment, $ap2Locked);

        $this->sessionStore->save($salesChannelContext, $metadata);
    }

    /**
     * @param list<string>                                                                                        $discountCodes
     * @param array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null $guestAddress
     * @param array<string, mixed>|null                                                                           $selectedPayment
     */
    public function saveForCheckoutId(
        string $checkoutId,
        SalesChannelContext $salesChannelContext,
        string $status,
        ?Buyer $buyer,
        array $discountCodes = [],
        ?string $orderId = null,
        ?string $orderDeepLinkCode = null,
        ?array $guestAddress = null,
        ?array $selectedPayment = null,
        bool $ap2Locked = false,
    ): void {
        $metadata = $this->metadata($salesChannelContext, $status, $buyer, $discountCodes, $orderId, $orderDeepLinkCode, $guestAddress, $selectedPayment, $ap2Locked);

        $this->sessionStore->save($salesChannelContext, $metadata);

        if ($checkoutId !== $salesChannelContext->getToken()) {
            $this->sessionStore->saveForToken($checkoutId, $salesChannelContext->getSalesChannelId(), $metadata);
        }
    }

    /**
     * @param list<string>                                                                                        $discountCodes
     * @param array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null $guestAddress
     * @param array<string, mixed>|null                                                                           $selectedPayment
     *
     * @return array<string, mixed>
     */
    private function metadata(
        SalesChannelContext $salesChannelContext,
        string $status,
        ?Buyer $buyer,
        array $discountCodes,
        ?string $orderId,
        ?string $orderDeepLinkCode,
        ?array $guestAddress,
        ?array $selectedPayment = null,
        bool $ap2Locked = false,
    ): array {
        $metadata = [
            'status' => $status,
            'buyer' => $this->buyerPayload($buyer),
            'shopwareContextToken' => $salesChannelContext->getToken(),
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

        if (null !== $orderDeepLinkCode) {
            $metadata['orderDeepLinkCode'] = $orderDeepLinkCode;
        }

        if (null !== $guestAddress) {
            $metadata['guestAddress'] = $guestAddress;
        }

        if (null !== $selectedPayment) {
            $metadata['selectedPayment'] = $selectedPayment;
        }

        if ($ap2Locked) {
            $metadata['ap2Locked'] = true;
        }

        return $metadata;
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
