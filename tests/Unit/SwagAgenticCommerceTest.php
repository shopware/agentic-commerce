<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/** @internal */
final class SwagAgenticCommerceTest extends TestCase
{
    #[Test]
    public function testItLetsShopwareInstallComposerDependencies(): void
    {
        $plugin = new SwagAgenticCommerce(true, \dirname(__DIR__, 2));

        self::assertTrue($plugin->executeComposerCommands());
    }
}
