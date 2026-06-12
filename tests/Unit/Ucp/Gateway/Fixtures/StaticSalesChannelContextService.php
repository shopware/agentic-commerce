<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures;

use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/** @internal */
final class StaticSalesChannelContextService implements SalesChannelContextServiceInterface
{
    public function __construct(private readonly SalesChannelContext $context)
    {
    }

    public function get(SalesChannelContextServiceParameters $parameters): SalesChannelContext
    {
        return $this->context;
    }
}
