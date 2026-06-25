<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Adapter;

use Shopware\Core\PlatformRequest;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareOrderGateway;
use Ucp\Sdk\Adapter\OrderAdapterInterface;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\RequestContext;

final class ShopwareOrderAdapter implements OrderAdapterInterface
{
    public function __construct(
        private readonly ShopwareOrderGateway $gateway,
        private readonly ShopwareDataMapper $mapper,
    ) {
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        $checkoutId = $this->checkoutId($context);

        return $this->mapper->toOrderView(
            $this->gateway->getOrder($id, $context),
            $checkoutId,
            $this->permalinkUrl($id, $context),
        );
    }

    private function checkoutId(RequestContext $context): ?string
    {
        $headers = array_change_key_case($context->headers, \CASE_LOWER);
        $token = $headers[strtolower(PlatformRequest::HEADER_CONTEXT_TOKEN)] ?? null;

        return \is_string($token) && '' !== $token ? $token : null;
    }

    private function permalinkUrl(string $orderId, RequestContext $context): string
    {
        $baseUri = $context->runtimeConfiguration?->baseUri;
        if (\is_string($baseUri) && '' !== $baseUri) {
            return rtrim($baseUri, '/').'/account/order/'.$orderId;
        }

        $host = $context->host;
        if ('' !== $host) {
            return 'https://'.$host.'/account/order/'.$orderId;
        }

        return 'urn:shopware:order:'.$orderId;
    }
}
