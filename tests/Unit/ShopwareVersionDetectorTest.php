<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;

/** @internal */
#[CoversClass(ShopwareVersionDetector::class)]
final class ShopwareVersionDetectorTest extends TestCase
{
    #[Test]
    public function testItUsesTheConfiguredRuntimeVersion(): void
    {
        $detector = new ShopwareVersionDetector(versionOverride: '6.6.9.0');

        self::assertSame('6.6.9.0', $detector->currentVersion());
        self::assertFalse($detector->supportsStoreApiMcp());
    }

    #[Test]
    public function testItFallsBackToTheKernelVersionParameterBeforeStaticFallbacks(): void
    {
        $detector = new ShopwareVersionDetector(kernelVersion: '6.7.1.0');

        self::assertSame('6.7.1.0', $detector->currentVersion());
    }

    #[Test]
    public function testItNeedsToPatchRobotsTrackingAllowOnlyBetweenSixSevenOneAndThirteen(): void
    {
        self::assertFalse((new ShopwareVersionDetector(versionOverride: '6.6.10.0'))->needsRobotsTrackingAllowPatch());
        self::assertFalse((new ShopwareVersionDetector(versionOverride: '6.7.0.0'))->needsRobotsTrackingAllowPatch());
        self::assertTrue((new ShopwareVersionDetector(versionOverride: '6.7.1.0'))->needsRobotsTrackingAllowPatch());
        self::assertTrue((new ShopwareVersionDetector(versionOverride: '6.7.12.0'))->needsRobotsTrackingAllowPatch());
        self::assertFalse((new ShopwareVersionDetector(versionOverride: '6.7.13.0'))->needsRobotsTrackingAllowPatch());
    }

    #[Test]
    public function testItNeedsTheSystemConfigXsdCompatPatchOnlyOnSixFive(): void
    {
        self::assertTrue((new ShopwareVersionDetector(versionOverride: '6.5.0.0'))->needsSystemConfigXsdCompatPatch());
        self::assertTrue((new ShopwareVersionDetector(versionOverride: '6.5.9.0'))->needsSystemConfigXsdCompatPatch());
        self::assertFalse((new ShopwareVersionDetector(versionOverride: '6.6.0.0'))->needsSystemConfigXsdCompatPatch());
        self::assertFalse((new ShopwareVersionDetector(versionOverride: '6.7.13.0'))->needsSystemConfigXsdCompatPatch());
        // Unknown/unresolvable version must fall through to core's real schema.
        self::assertFalse((new ShopwareVersionDetector(versionOverride: '0.0.0.0'))->needsSystemConfigXsdCompatPatch());
    }
}
