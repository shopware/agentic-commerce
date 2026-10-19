<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Profile\ProfileDraftConfig;

/**
 * @internal
 */
#[CoversClass(ProfileDraftConfig::class)]
final class ProfileDraftConfigTest extends TestCase
{
    public function testAFailingBuildDoesNotLeaveItsDraftForTheNextBuild(): void
    {
        $draftConfig = new ProfileDraftConfig();

        try {
            $draftConfig->during(UcpConfig::fromArray(['active' => true]), static function (): never {
                throw new \RuntimeException('build failed');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('build failed', $exception->getMessage(), 'the build failure reaches the caller');
        }

        self::assertNull($draftConfig->current());
    }

    public function testANestedBuildRestoresTheOuterDraft(): void
    {
        $draftConfig = new ProfileDraftConfig();
        $outer = UcpConfig::fromArray(['active' => true]);
        $inner = UcpConfig::fromArray(['active' => false]);

        $seenAfterInner = $draftConfig->during($outer, static function () use ($draftConfig, $inner): ?UcpConfig {
            $draftConfig->during($inner, static fn (): ?UcpConfig => $draftConfig->current());

            return $draftConfig->current();
        });

        self::assertSame($outer, $seenAfterInner);
        self::assertNull($draftConfig->current());
    }
}
