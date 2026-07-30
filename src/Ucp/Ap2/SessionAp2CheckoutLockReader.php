<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Ap2;

use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Ucp\Sdk\Model\RequestContext;

final class SessionAp2CheckoutLockReader implements Ap2CheckoutLockReaderInterface
{
    public function __construct(
        private readonly SalesChannelContextResolver $contextResolver,
        private readonly CheckoutSessionStore $sessionStore,
    ) {
    }

    public function isLocked(string $checkoutId, RequestContext $context): bool
    {
        $resolution = $this->contextResolver->resolveSalesChannel($context);

        return $this->sessionStore->ap2Locked($this->sessionStore->load($checkoutId, $resolution->salesChannelId));
    }
}
