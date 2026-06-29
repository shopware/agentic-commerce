<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Adapter;

use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareOrderGateway;
use Ucp\Sdk\Adapter\OrderAdapterInterface;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareOrderAdapter implements OrderAdapterInterface
{
    public function __construct(
        private readonly ShopwareOrderGateway $gateway,
        private readonly ShopwareDataMapper $mapper,
        private readonly CheckoutCompletionStoreInterface $completionStore,
    ) {
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        $order = $this->gateway->getOrder($id, $context);

        return $this->mapper->toOrderView(
            $order,
            $this->orderPermalink($order->getId(), $context),
            $this->completionStore->completedCheckoutId($order->getId()) ?? $order->getId(),
        );
    }

    private function orderPermalink(string $orderId, RequestContext $context): string
    {
        $baseUri = $context->runtimeConfiguration?->baseUri;
        if (null === $baseUri || '' === $baseUri) {
            $baseUri = 'https://'.$context->host;
        }

        return rtrim($baseUri, '/').'/account/order/'.rawurlencode($orderId);
    }
}
