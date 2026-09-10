<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Checkout\Order\OrderEntity;
use Ucp\Sdk\Model\RequestContext;

/**
 * Builds the absolute permalink to a placed order.
 *
 * `types/order_confirmation.json` and `order.json` both mark `permalink_url` REQUIRED and
 * `format: uri`, so omitting it — which is what happened on any sales channel without a
 * `continueUrlTemplate`, because the nullable continue URL was passed as the permalink —
 * makes the shop's own response fail the SDK's response validation with the opaque
 * `$ must match exactly one allowed schema` on GET and on complete.
 *
 * The link is Shopware's own order page addressed by **deep-link code**, which is the one
 * URL that works for every buyer. Verified against core (trunk):
 *
 *   * `AccountOrderPageLoader::load()` refuses only when there is neither a customer NOR a
 *     `deepLinkCode`, and then filters on the code without caring whether anyone is logged
 *     in. A guest is asked for email and postcode (`GuestNotAuthenticatedException` →
 *     `frontend.account.guest.login.page`); a logged-in customer sees the order directly.
 *   * every core order-state mail links exactly this way —
 *     `rawUrl('frontend.account.order.single.page', {'deepLinkCode': order.deepLinkCode},
 *     salesChannel.domains|first.url)` — sent to guests and registered customers alike with
 *     no branching, because the sender cannot know whether the recipient is logged in.
 *
 * Two URLs this deliberately does NOT build:
 *
 *   * `/ucp/v1/orders/{id}`, the UCP endpoint. Measured: a browser gets
 *     `422 $.headers.ucp-agent is required`, and a guest could not authenticate it anyway,
 *     because completion rotates the Shopware context token and the response never hands the
 *     successor back. A permalink only an agent-with-a-session can open is not "the
 *     authoritative reference for the full order experience".
 *   * `/account/order/{orderId}`, the storefront page addressed by order id. That route
 *     resolves a deep-link code, so an id matches nothing — and both spellings render the
 *     same guest form, which is why it looked fine.
 *
 * @internal
 */
final class OrderPermalinkBuilder
{
    public function build(OrderEntity $order, RequestContext $context): string
    {
        $baseUri = $context->runtimeConfiguration?->baseUri;
        if (null === $baseUri || '' === $baseUri) {
            $baseUri = 'https://'.$context->host;
        }

        $baseUri = rtrim($baseUri, '/');
        $deepLinkCode = $order->getDeepLinkCode();

        // `deep_link_code` is nullable in the DAL and a permalink is required, so there has
        // to be an answer either way. The order list is the truthful one: a logged-in buyer
        // finds the order there and anyone else is asked to log in, which beats a URL built
        // from an id the route cannot resolve.
        if (!\is_string($deepLinkCode) || '' === $deepLinkCode) {
            return $baseUri.'/account/order';
        }

        return $baseUri.'/account/order/'.rawurlencode($deepLinkCode);
    }
}
