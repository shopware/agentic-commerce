<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Ucp\Sdk\Model\RequestContext;

/**
 * Builds the absolute permalink to a placed order.
 *
 * The UCP checkout/order response schema requires `order.permalink_url` to be a
 * non-null absolute URI (`format: uri`). When it is omitted — e.g. because no
 * continue-URL template is configured for the sales channel — the shop's own
 * checkout response fails the SDK's response validation and surfaces the opaque
 * `$ must match exactly one allowed schema` error on both GET and complete.
 *
 * The link points at the UCP order endpoint so it stays machine-resolvable for
 * headless / agent sales channels that have no human storefront order page.
 *
 * @internal
 */
final class OrderPermalinkBuilder
{
    public function build(string $orderId, RequestContext $context): string
    {
        $baseUri = $context->runtimeConfiguration?->baseUri;
        if (null === $baseUri || '' === $baseUri) {
            $baseUri = 'https://'.$context->host;
        }

        return rtrim($baseUri, '/').'/ucp/v1/orders/'.rawurlencode($orderId);
    }
}
