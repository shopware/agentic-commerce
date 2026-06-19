<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Customer;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Common\Buyer;

interface GuestCustomerContextProvisionerInterface
{
    /**
     * @param array{street: string, zipcode: string, city: string, countryCode?: string, countryId?: string}|null $guestAddress
     */
    public function ensureGuestCustomer(
        SalesChannelContext $context,
        ?Buyer $buyer,
        ?array $guestAddress = null,
    ): SalesChannelContext;
}
