<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Adapter\OrderAdapterInterface;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Model\Order\OrderView;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class OrderCapability implements OrderCapabilityInterface
{
    public function __construct(
        private readonly OrderAdapterInterface $adapter,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_ORDER);
    }

    public function getOrder(string $id, RequestContext $context): OrderView
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_ORDER, 'Order capability is disabled for this sales channel.');

        return $this->adapter->getOrder($id, $context);
    }
}
