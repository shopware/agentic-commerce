<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Cart;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Remembers which context tokens UCP handed out as cart ids.
 *
 * A UCP cart id is a Shopware context token, and Shopware resolves a context and an empty
 * cart for any token it is shown. That is the right behaviour for a storefront session and
 * the wrong one for a protocol: an agent asking for a cart nobody created must be told it does
 * not exist, not handed a fresh one under the id it guessed. The marker lives in the same
 * `sales_channel_api_context` payload the checkout session already uses, so no table is added
 * and a token a checkout persisted counts as known too.
 */
#[Package('checkout')]
final class CartSessionStore
{
    private const PAYLOAD_KEY = 'swagAgenticCommerce';
    private const CART_KEY = 'ucpCart';

    public function __construct(
        private readonly SalesChannelContextPersister $persister,
    ) {
    }

    public function register(SalesChannelContext $context): void
    {
        $this->persister->save(
            $context->getToken(),
            [
                self::PAYLOAD_KEY => [
                    self::CART_KEY => ['registered' => true],
                ],
            ],
            $context->getSalesChannelId(),
            $context->getCustomer()?->getId(),
        );
    }

    /**
     * A token is known once anything persisted a context under it: a cart created through
     * UCP, or a checkout session. Shopware's persister answers an empty array for a token it
     * has never stored, so that is the whole test.
     */
    public function isKnown(SalesChannelContext $context): bool
    {
        return [] !== $this->persister->load($context->getToken(), $context->getSalesChannelId());
    }
}
