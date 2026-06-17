<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures;

use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartDeleteRoute;
use Shopware\Core\System\SalesChannel\NoContentResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/** @internal */
final class RecordingCartDeleteRoute extends AbstractCartDeleteRoute
{
    public int $deleteCalls = 0;

    public function getDecorated(): AbstractCartDeleteRoute
    {
        throw new \BadMethodCallException('Decoration is not supported in tests.');
    }

    public function delete(SalesChannelContext $context): NoContentResponse
    {
        ++$this->deleteCalls;

        return new NoContentResponse();
    }
}
