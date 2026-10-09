<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * Before 6.7.10 core has no `Defaults.agenticCommerceTypeId`, so the administration sets it from
 * `init/defaults.init.js`. That copy has to stay the id the plugin registers the sales channel
 * type with, or the admin filters and tabs look for a type that does not exist. Reads the shipped
 * module directly, like the AdminTemplateModuleTrait tests read the export templates.
 *
 * @internal
 */
#[CoversNothing]
final class AdministrationDefaultsTypeIdTest extends TestCase
{
    #[Test]
    public function testAdministrationDefaultsUseThePluginSalesChannelTypeId(): void
    {
        $path = __DIR__.'/../../src/Resources/app/administration/src/init/defaults.init.js';
        $module = file_get_contents($path);

        static::assertIsString($module, \sprintf('Administration module is not readable: %s', $path));
        static::assertSame(
            1,
            preg_match_all("/^\\s*Shopware\\.Defaults\\.agenticCommerceTypeId = '([0-9a-f]{32})';/m", $module, $matches),
            'defaults.init.js must assign exactly one literal agenticCommerceTypeId.',
        );
        static::assertSame(SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE, $matches[1][0]);
    }
}
