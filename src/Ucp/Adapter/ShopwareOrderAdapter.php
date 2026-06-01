<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Adapter;

use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareOrderGateway;
use Ucp\Sdk\Adapter\OrderAdapterInterface;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\RequestContext;

final readonly class ShopwareOrderAdapter implements OrderAdapterInterface
{
    public function __construct(
        private ShopwareOrderGateway $gateway,
        private ShopwareDataMapper $mapper,
    ) {
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        return $this->mapper->toOrderView($this->gateway->getOrder($id, $context));
    }
}
