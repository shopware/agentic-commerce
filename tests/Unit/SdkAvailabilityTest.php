<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\SdkAvailability;

/**
 * A zip install extracts the plugin one request before Shopware runs `composer require`, so an
 * active plugin boots at least once without its dependency. What it does in that request decides
 * whether the shop stays up, which is why the check is asserted here rather than assumed.
 *
 * The version case is the one that matters on every later update: a shop that still has the
 * previous SDK would pass a `class_exists` test and then fail on the first class the new code
 * needs, so a mismatch has to read as unusable.
 *
 * @internal
 */
#[CoversClass(SdkAvailability::class)]
final class SdkAvailabilityTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            @unlink($root.'/composer.json');
            @unlink($root.'/swag-agentic-commerce.log');
            @rmdir($root);
        }

        $this->roots = [];

        parent::tearDown();
    }

    #[Test]
    public function testTheInstalledSdkThisSuiteRunsAgainstIsUsable(): void
    {
        self::assertNull(SdkAvailability::reason(\dirname(__DIR__, 2)));
        self::assertTrue(SdkAvailability::isUsable(\dirname(__DIR__, 2)));
    }

    #[Test]
    public function testAnSdkOlderThanThePinnedOneIsNotUsable(): void
    {
        $root = $this->pluginRootRequiring('9.9.9');

        $reason = SdkAvailability::reason($root);

        self::assertIsString($reason);
        self::assertStringContainsString('ucp-php-sdk/core', $reason);
        self::assertStringContainsString('9.9.9', $reason);
        self::assertFalse(SdkAvailability::isUsable($root));
    }

    #[Test]
    public function testAManifestThatPinsNothingFallsBackToWhatIsInstalled(): void
    {
        // Deliberate: with no constraint to compare against, an SDK that is installed and loadable
        // is accepted rather than refused. The alternative would switch the plugin off over a
        // manifest it could not read, which is a worse failure than the one this guards.
        $root = $this->pluginRoot(['acme/unrelated' => '1.0.0']);

        self::assertNull(SdkAvailability::reason($root));
    }

    #[Test]
    public function testItLogsWhatToTypeToFixIt(): void
    {
        $root = $this->pluginRootRequiring('9.9.9');

        SdkAvailability::logUnusable($root, $root);

        $log = (string) file_get_contents($root.'/swag-agentic-commerce.log');

        self::assertStringContainsString('composer require ucp-php-sdk/core:9.9.9', $log);
        self::assertStringContainsString('ucp-php-sdk/symfony-bundle:9.9.9', $log);
        self::assertStringContainsString('registers no services, routes or feeds', $log);
    }

    #[Test]
    public function testAnAbsentManifestDoesNotRaise(): void
    {
        $root = sys_get_temp_dir().'/swag-agentic-no-manifest-'.bin2hex(random_bytes(6));
        mkdir($root);
        $this->roots[] = $root;

        // Called while the container is compiling, where throwing is the one thing it must not do.
        self::assertNull(SdkAvailability::reason($root));
    }

    private function pluginRootRequiring(string $version): string
    {
        return $this->pluginRoot([
            'ucp-php-sdk/core' => $version,
            'ucp-php-sdk/symfony-bundle' => $version,
        ]);
    }

    /**
     * @param array<string, string> $require
     */
    private function pluginRoot(array $require): string
    {
        $root = sys_get_temp_dir().'/swag-agentic-sdk-availability-'.bin2hex(random_bytes(6));
        mkdir($root);
        $this->roots[] = $root;

        file_put_contents($root.'/composer.json', (string) json_encode(['require' => $require]));

        return $root;
    }
}
