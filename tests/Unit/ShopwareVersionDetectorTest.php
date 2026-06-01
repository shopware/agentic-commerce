<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;

/** @internal */
final class ShopwareVersionDetectorTest extends TestCase
{
    /** @test */
    #[Test]
    public function itUsesTheConfiguredRuntimeVersion(): void
    {
        $detector = new ShopwareVersionDetector(versionOverride: '6.6.9.0');

        self::assertSame('6.6.9.0', $detector->currentVersion());
        self::assertFalse($detector->supportsAgenticDiscovery());
        self::assertFalse($detector->supportsStoreApiMcp());
    }

    /** @test */
    #[Test]
    public function itFallsBackToTheKernelVersionParameterBeforeStaticFallbacks(): void
    {
        $detector = new ShopwareVersionDetector(kernelVersion: '6.7.1.0');

        self::assertSame('6.7.1.0', $detector->currentVersion());
    }
}
