<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Exception;

use Shopware\Core\Framework\Log\Package;

#[Package('framework')]
final class SdkNotAvailableException extends \RuntimeException
{
    public static function bundleCouldNotBeLoaded(): self
    {
        return new self('Unable to load the UCP SDK bundle from the standalone plugin checkout.');
    }
}
