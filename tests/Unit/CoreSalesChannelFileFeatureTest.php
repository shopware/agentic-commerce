<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;

/** @internal */
final class CoreSalesChannelFileFeatureTest extends TestCase
{
    public function testItIsAvailableWhenAllRequiredClassesExist(): void
    {
        $feature = new CoreSalesChannelFileFeature([self::class]);

        static::assertTrue($feature->isAvailable());
    }

    public function testItIsUnavailableWhenARequiredClassIsMissing(): void
    {
        $feature = new CoreSalesChannelFileFeature([
            self::class,
            'Swag\\AgenticCommerce\\Tests\\Unit\\MissingCoreSalesChannelFileClass',
        ]);

        static::assertFalse($feature->isAvailable());
    }
}
