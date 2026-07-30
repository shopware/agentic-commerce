<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Ap2;

use Ucp\Sdk\Model\RequestContext;

interface Ap2CheckoutLockReaderInterface
{
    public function isLocked(string $checkoutId, RequestContext $context): bool;
}
