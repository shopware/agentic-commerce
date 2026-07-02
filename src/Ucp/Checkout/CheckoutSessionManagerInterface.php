<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Common\Buyer;

/** @internal */
interface CheckoutSessionManagerInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function buyer(array $metadata): ?Buyer;

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null
     */
    public function guestAddress(array $metadata): ?array;

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
        ?string $orderDeepLinkCode = null,
        ?array $guestAddress = null,
    ): void;

    /**
     * @param list<string>                                                                                        $discountCodes
     * @param array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null $guestAddress
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
    ): void;
}
