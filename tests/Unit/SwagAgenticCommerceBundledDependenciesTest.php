<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Parameter\AdditionalBundleParameters;
use Shopware\Core\Framework\Plugin\KernelPluginCollection;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * A store ZIP vendors the UCP SDK into the plugin's own `vendor/`, and Shopware never loads it.
 *
 * `KernelPluginLoader` registers a plugin's `autoload.psr-4` from its composer.json and nothing
 * else -- it does not require `custom/plugins/<Plugin>/vendor/autoload.php`. So unless the plugin
 * does it itself, every `Ucp\Sdk\...` class shipped in the archive is present on disk and
 * invisible, and `plugin:install` fails with SdkNotAvailableException on any shop that does not
 * separately provide the SDK. That is what shipped, and what QA hit on 6.5, 6.6 and 6.7 alike.
 *
 * It survived our own testing because every dev lane installs the SDK at project level as a
 * Composer path repository, so the class was always reachable by another route and the bundled
 * copy was never once exercised.
 *
 * These tests plant a sentinel autoloader in a temporary plugin root: if an entry point loads it,
 * a marker constant is defined. That asserts the behaviour rather than the shape of the source,
 * and it fails for both ways this has actually broken -- not loading the file at all, and looking
 * for it under `getPath()` (which is `<plugin>/src`) instead of `getBasePath()`.
 *
 * @internal
 */
#[CoversClass(SwagAgenticCommerce::class)]
final class SwagAgenticCommerceBundledDependenciesTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            @unlink($dir.'/vendor/autoload.php');
            @rmdir($dir.'/vendor');
            @rmdir($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    #[Test]
    public function testGetAdditionalBundlesLoadsTheAutoloaderTheArchiveShips(): void
    {
        $root = $this->sentinelPluginRoot(__FUNCTION__);
        $plugin = new SwagAgenticCommerce(true, $root['basePath']);

        $plugin->getAdditionalBundles($this->bundleParameters());

        self::assertTrue(
            \defined($root['constant']),
            'getAdditionalBundles() must require the plugin\'s own vendor/autoload.php: a store '
            .'ZIP vendors the SDK there and Shopware does not load it.',
        );
    }

    /**
     * The distinguishing check, and the one that would have caught the first attempt at the fix.
     *
     * Shopware sets a plugin's path to the directory of its plugin class (`<plugin>/src`), while
     * the vendored dependencies sit at the plugin root. Using `getPath()` to find them fails
     * exactly as if the fix were absent, which is why asserting "an autoloader is required" is
     * not enough on its own -- the path has to be the root.
     */
    #[Test]
    public function testTheAutoloaderIsLookedForAtThePluginRootNotTheSourceDirectory(): void
    {
        $root = $this->sentinelPluginRoot(__FUNCTION__);
        $plugin = new SwagAgenticCommerce(true, $root['basePath']);

        self::assertSame($root['basePath'], $plugin->getBasePath(), 'getBasePath() is the plugin root.');
        self::assertNotSame(
            $plugin->getBasePath(),
            $plugin->getPath(),
            'getPath() is <plugin>/src, so the two are not interchangeable for finding vendor/.',
        );

        $plugin->getAdditionalBundles($this->bundleParameters());

        self::assertTrue(
            \defined($root['constant']),
            'The autoloader must be resolved from getBasePath(); getPath() points at src/ and '
            .'finds nothing.',
        );
    }

    /**
     * A Composer-managed shop resolves the SDK itself and ships no bundled vendor directory. The
     * entry points have to stay usable there rather than warning or failing on the missing file.
     */
    #[Test]
    public function testAMissingBundledVendorDirectoryIsNotAnError(): void
    {
        $basePath = sys_get_temp_dir().'/swag-agentic-no-vendor-'.substr(sha1(uniqid('', true)), 0, 12);
        if (!mkdir($basePath, 0o777, true) && !is_dir($basePath)) {
            self::fail('Could not create the sentinel plugin root.');
        }
        $this->tempDirs[] = $basePath;

        $plugin = new SwagAgenticCommerce(true, $basePath);
        $plugin->getAdditionalBundles($this->bundleParameters());

        self::assertDirectoryDoesNotExist($basePath.'/vendor');
    }

    private function bundleParameters(): AdditionalBundleParameters
    {
        return new AdditionalBundleParameters(new ClassLoader(), new KernelPluginCollection(), []);
    }

    /**
     * @return array{basePath: string, constant: string}
     */
    private function sentinelPluginRoot(string $seed): array
    {
        $basePath = sys_get_temp_dir().'/swag-agentic-bundled-'.substr(sha1($seed.uniqid('', true)), 0, 12);
        $constant = 'SWAG_AGENTIC_BUNDLED_AUTOLOAD_'.strtoupper(substr(sha1($seed), 0, 10));

        if (!mkdir($basePath.'/vendor', 0o777, true) && !is_dir($basePath.'/vendor')) {
            self::fail('Could not create the sentinel plugin root.');
        }

        file_put_contents(
            $basePath.'/vendor/autoload.php',
            "<?php\n\ndefine(".var_export($constant, true).", true);\n",
        );

        $this->tempDirs[] = $basePath;

        return ['basePath' => $basePath, 'constant' => $constant];
    }
}
