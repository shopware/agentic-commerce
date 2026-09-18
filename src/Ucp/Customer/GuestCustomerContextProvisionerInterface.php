<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Customer;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Common\Buyer;

/** @internal */
interface GuestCustomerContextProvisionerInterface
{
    /**
     * @param array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null $guestAddress
     * @param array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null $guestShippingAddress
     */
    public function ensureGuestCustomer(
        SalesChannelContext $context,
        ?Buyer $buyer,
        ?array $guestAddress = null,
        ?array $guestShippingAddress = null,
    ): SalesChannelContext;
}
