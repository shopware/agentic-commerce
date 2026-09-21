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
 * arranges it, every `Ucp\Sdk\...` class shipped in the archive is present on disk and invisible,
 * and `plugin:install` fails with SdkNotAvailableException on any shop that does not separately
 * provide the SDK. That is what shipped in 1.3.0, and what QA hit on 6.5, 6.6 and 6.7 alike.
 *
 * It survived our own testing because every dev lane installs the SDK at project level as a
 * Composer path repository, so the class was always reachable by another route and the bundled
 * copy was never once exercised.
 *
 * The archive now declares the bundled packages in its own `autoload.psr-4` instead, so Shopware's
 * class loader resolves them and the plugin registers nothing. What is left in the plugin is a
 * fallback for the two cases that miss: a lifecycle command that refreshed the `plugin.autoload`
 * column inside an already-booted kernel, and a development lane whose SDK lives in the plugin's
 * own `vendor/`. Each rung is asserted here by planting sentinels in a temporary plugin root and
 * observing what gets loaded -- behaviour, not the shape of the source.
 *
 * @internal
 */
#[CoversClass(SwagAgenticCommerce::class)]
final class SwagAgenticCommerceBundledDependenciesTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    /** @var list<callable> */
    private array $autoloadersBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->autoloadersBefore = spl_autoload_functions();
    }

    protected function tearDown(): void
    {
        // registerBundledNamespaces() registers a process-wide autoloader. Leaving it in place
        // would let one case resolve another's sentinels.
        foreach (spl_autoload_functions() as $autoloader) {
            if (!\in_array($autoloader, $this->autoloadersBefore, true)) {
                spl_autoload_unregister($autoloader);
            }
        }

        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->removeRecursively($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * The normal case, on every shop: the SDK is already resolvable -- from the project autoloader
     * on a Composer install, or from the prefixes Shopware registered out of the archive's own
     * composer.json -- so the plugin must touch nothing at all.
     */
    #[Test]
    public function testAResolvableSdkLeavesTheShopsAutoloadingAlone(): void
    {
        self::assertTrue(
            class_exists('Ucp\\Sdk\\Symfony\\UcpSdkBundle'),
            'This case only means something where the SDK is already loadable.',
        );

        $root = $this->pluginRoot();
        $constant = $this->plantBundledAutoloader($root);
        $plugin = new SwagAgenticCommerce(true, $root);

        $plugin->getAdditionalBundles($this->bundleParameters());

        self::assertFalse(
            \defined($constant),
            'A shop that resolves the SDK itself must not have the plugin require a second '
            .'Composer autoloader: that one registers in InstalledVersions and can shadow the '
            .'shop\'s own package versions.',
        );
    }

    /**
     * The zip case. `plugin:install -r` and `plugin:update-all` refresh the `plugin.autoload`
     * column and then run the lifecycle method in the same process, so the prefixes the archive
     * declares are a boot behind and Shopware has not registered them yet.
     */
    #[Test]
    public function testTheManifestsOwnPsr4PrefixesAreRegisteredWhenShopwareHasNotYet(): void
    {
        $root = $this->pluginRoot();
        $class = $this->plantPsr4Package($root, 'vendor/acme/bundled/src');
        $plugin = new SwagAgenticCommerce(true, $root);

        self::assertFalse(class_exists($class, false), 'The sentinel must not be loaded up front.');

        $this->invoke($plugin, 'registerBundledNamespaces');

        self::assertTrue(
            class_exists($class),
            'A prefix the plugin declares in its own composer.json has to be resolvable after '
            .'this runs; that is the only thing standing in for Shopware in that window.',
        );
    }

    /**
     * The plugin's own prefix is Shopware's to register, and it is already registered by the time
     * any of this runs. Re-registering it from the manifest would put a second mapping for
     * `Swag\AgenticCommerce\` in front of the shop's.
     */
    #[Test]
    public function testThePluginsOwnPrefixIsNotReRegistered(): void
    {
        $root = $this->pluginRoot();
        $this->plantPsr4Package($root, 'vendor/acme/bundled/src');
        $plugin = new SwagAgenticCommerce(true, $root);

        $prefixes = $this->invoke($plugin, 'bundledPsr4Prefixes');

        self::assertIsArray($prefixes);
        self::assertArrayNotHasKey('Swag\\AgenticCommerce\\', $prefixes);
        self::assertSame(
            [$root.'/vendor/acme/bundled/src/'],
            $prefixes['Acme\\Bundled\\'] ?? null,
            'Declared paths are resolved against the plugin root and kept as directories.',
        );
    }

    /**
     * A development lane installs the SDK into the plugin's own `vendor/` so a project-level
     * `composer update` cannot drop it again, and relies on the autoloader Composer generates
     * there. That has to keep working, which is why the rung still exists.
     */
    #[Test]
    public function testAVendoredAutoloaderIsStillLoadedWhenNothingElseResolvesTheSdk(): void
    {
        $root = $this->pluginRoot();
        $constant = $this->plantBundledAutoloader($root);
        $plugin = new SwagAgenticCommerce(true, $root);

        $this->invoke($plugin, 'requireBundledAutoloader');

        self::assertTrue(\defined($constant), 'A plugin-local vendor/autoload.php must be loaded.');
    }

    /**
     * The check that would have caught the first attempt at this fix.
     *
     * Shopware sets a plugin's path to the directory of its plugin class (`<plugin>/src`), while
     * the vendored dependencies sit at the plugin root. Looking under `getPath()` fails exactly as
     * if the code were absent.
     */
    #[Test]
    public function testTheVendoredAutoloaderIsLookedForAtThePluginRootNotTheSourceDirectory(): void
    {
        $root = $this->pluginRoot();
        $plugin = new SwagAgenticCommerce(true, $root);

        self::assertSame($root, $plugin->getBasePath(), 'getBasePath() is the plugin root.');
        self::assertNotSame(
            $plugin->getBasePath(),
            $plugin->getPath(),
            'getPath() is <plugin>/src, so the two are not interchangeable for finding vendor/.',
        );

        // Planted where getPath() would look, and nowhere else.
        mkdir($root.'/src/vendor', 0o777, true);
        $constant = 'SWAG_AGENTIC_WRONG_PATH_'.strtoupper(bin2hex(random_bytes(5)));
        file_put_contents(
            $root.'/src/vendor/autoload.php',
            '<?php define('.var_export($constant, true).', true);'."\n",
        );

        $this->invoke($plugin, 'requireBundledAutoloader');

        self::assertFalse(\defined($constant), 'vendor/ sits at the plugin root, not under src/.');
    }

    /**
     * A Composer-managed shop ships neither a bundled vendor directory nor extra prefixes, and a
     * manifest is not guaranteed to be readable at all. None of that may raise.
     */
    #[Test]
    public function testAPluginRootWithNeitherAManifestNorAVendorDirectoryIsNotAnError(): void
    {
        $root = $this->pluginRoot();
        $plugin = new SwagAgenticCommerce(true, $root);

        self::assertSame([], $this->invoke($plugin, 'bundledPsr4Prefixes'));

        $this->invoke($plugin, 'registerBundledNamespaces');
        $this->invoke($plugin, 'requireBundledAutoloader');

        file_put_contents($root.'/composer.json', '{ not json');

        self::assertSame(
            [],
            $this->invoke($plugin, 'bundledPsr4Prefixes'),
            'An unreadable manifest is not something a plugin entry point may die on.',
        );
    }

    private function bundleParameters(): AdditionalBundleParameters
    {
        return new AdditionalBundleParameters(new ClassLoader(), new KernelPluginCollection(), []);
    }

    private function invoke(SwagAgenticCommerce $plugin, string $method): mixed
    {
        $reflection = new \ReflectionMethod($plugin, $method);

        return $reflection->invoke($plugin);
    }

    private function pluginRoot(): string
    {
        $root = sys_get_temp_dir().'/swag-agentic-bundled-'.bin2hex(random_bytes(6));

        if (!mkdir($root, 0o777, true) && !is_dir($root)) {
            self::fail('Could not create the sentinel plugin root.');
        }

        $this->tempDirs[] = $root;

        return $root;
    }

    /**
     * Writes a `vendor/autoload.php` that announces itself through a constant.
     */
    private function plantBundledAutoloader(string $root): string
    {
        $constant = 'SWAG_AGENTIC_BUNDLED_AUTOLOAD_'.strtoupper(bin2hex(random_bytes(5)));

        mkdir($root.'/vendor', 0o777, true);
        file_put_contents(
            $root.'/vendor/autoload.php',
            '<?php define('.var_export($constant, true).', true);'."\n",
        );

        return $constant;
    }

    /**
     * Writes a manifest declaring the plugin's own prefix plus one bundled package, and a class
     * that only the second prefix can resolve.
     *
     * @return class-string the planted class
     */
    private function plantPsr4Package(string $root, string $path): string
    {
        $class = 'Widget'.strtoupper(bin2hex(random_bytes(5)));

        mkdir($root.'/'.$path, 0o777, true);
        file_put_contents(
            $root.'/'.$path.'/'.$class.'.php',
            '<?php namespace Acme\\Bundled; class '.$class.' {}'."\n",
        );

        file_put_contents($root.'/composer.json', json_encode([
            'name' => 'shopware/agentic-commerce',
            'autoload' => [
                'psr-4' => [
                    'Swag\\AgenticCommerce\\' => 'src/',
                    'Acme\\Bundled\\' => [$path.'/'],
                ],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        /** @var class-string $fqcn */
        $fqcn = 'Acme\\Bundled\\'.$class;

        return $fqcn;
    }

    private function removeRecursively(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    $this->removeRecursively($path.'/'.$entry);
                }
            }

            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
