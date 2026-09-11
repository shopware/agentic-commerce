<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/** @internal */
final class SwagAgenticCommerceTest extends TestCase
{
    /** Uses a temporary file because the packaged-install marker is the behavior under test. */
    #[Test]
    public function testPackagedSdkDoesNotTriggerAnotherComposerResolve(): void
    {
        $directory = sys_get_temp_dir().'/agentic-bundled-sdk-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $marker = $directory.'/.swag-agentic-commerce-bundled-sdk';
        touch($marker);

        try {
            $plugin = new SwagAgenticCommerce(true, $directory);

            self::assertFalse($plugin->executeComposerCommands());
        } finally {
            unlink($marker);
            rmdir($directory);
        }
    }

    #[Test]
    public function testItLetsShopwareInstallComposerDependencies(): void
    {
        $plugin = new SwagAgenticCommerce(true, \dirname(__DIR__, 2));

        self::assertTrue($plugin->executeComposerCommands());
    }
}
