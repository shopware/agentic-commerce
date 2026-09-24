<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('framework')]
final class TestDataException extends \RuntimeException
{
    public static function missingTax(): self
    {
        return new self('The shop has no tax rate to assign to the test products.');
    }
}
