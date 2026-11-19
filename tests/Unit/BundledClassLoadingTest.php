<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\BundledClassLoading;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
#[CoversClass(BundledClassLoading::class)]
final class BundledClassLoadingTest extends TestCase
{
    private const PLUGIN_CLASS = 'Swag\\AgenticCommerce\\Foo';

    private const PLUGIN_CLASS_WITHOUT_FILE = 'Swag\\AgenticCommerce\\RemovedInTheArchive';

    private const UNLISTED_PLUGIN_CLASS = 'Swag\\AgenticCommerce\\Unlisted';

    private const CORE_CLASS = 'Ucp\\Sdk\\Enum\\Transport';

    private const BUNDLE_CLASS = 'Ucp\\Sdk\\Symfony\\UcpSdkBundle';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->roots);
        $this->roots = [];

        parent::tearDown();
    }

    #[Test]
    public function testItAppendsTheManifestPrefixesTheLoaderLacks(): void
    {
        $pluginRoot = $this->pluginRoot(['autoload' => ['psr-4' => ['Swag\\Archive\\' => 'src/', 'Other\\Archive\\' => 'other/']]]);
        mkdir($pluginRoot.'/src');
        mkdir($pluginRoot.'/other');
        $loader = new ClassLoader();
        $loader->addPsr4('Swag\\Archive\\', $pluginRoot.'/elsewhere');

        BundledClassLoading::registerOn($loader, $pluginRoot.'/vendor', $pluginRoot);

        $directoriesByPrefix = $loader->getPrefixesPsr4();
        self::assertSame([$pluginRoot.'/elsewhere', realpath($pluginRoot.'/src')], $directoriesByPrefix['Swag\\Archive\\']);
        self::assertSame([realpath($pluginRoot.'/other')], $directoriesByPrefix['Other\\Archive\\']);
    }

    #[Test]
    public function testItDoesNotRegisterAPrefixTwice(): void
    {
        $pluginRoot = $this->pluginRoot(['autoload' => ['psr-4' => ['Swag\\Archive\\' => 'src/']]]);
        mkdir($pluginRoot.'/src');
        mkdir($pluginRoot.'/lib');
        $loader = new ClassLoader();
        $loader->addPsr4('Swag\\Archive\\', $pluginRoot.'/lib/../src');

        BundledClassLoading::registerOn($loader, $pluginRoot.'/vendor', $pluginRoot);
        BundledClassLoading::registerOn($loader, $pluginRoot.'/vendor', $pluginRoot);

        self::assertSame([$pluginRoot.'/lib/../src'], $loader->getPrefixesPsr4()['Swag\\Archive\\']);
    }

    #[Test]
    public function testTheBundledSdkWinsOverARootVendorEntryOfTheSamePrefix(): void
    {
        $projectRoot = $this->composerCopyProject(['require' => ['ucp-php-sdk/core' => '0.0.7']]);
        $pluginRoot = $projectRoot.'/custom/plugins/SwagAgenticCommerce';
        $loader = new ClassLoader($projectRoot.'/vendor');
        $loader->addPsr4('Ucp\\Sdk\\', $projectRoot.'/vendor/ucp-php-sdk/core/src');

        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $pluginRoot);

        self::assertSame(
            [realpath($pluginRoot.'/lib/ucp-php-sdk/core/src'), $projectRoot.'/vendor/ucp-php-sdk/core/src'],
            $loader->getPrefixesPsr4()['Ucp\\Sdk\\'],
        );
        self::assertSame(realpath($pluginRoot.'/lib/ucp-php-sdk/core/src/Enum/Transport.php'), $loader->findFile(self::CORE_CLASS));
    }

    #[Test]
    public function testTheBundledSdkDirectoriesStayInFrontWhenItRunsAgain(): void
    {
        $projectRoot = $this->composerCopyProject([]);
        $pluginRoot = $projectRoot.'/custom/plugins/SwagAgenticCommerce';
        $loader = new ClassLoader($projectRoot.'/vendor');
        $loader->addPsr4('Ucp\\Sdk\\', $projectRoot.'/vendor/ucp-php-sdk/core/src');

        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $pluginRoot);
        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $pluginRoot);

        self::assertCount(2, $loader->getPrefixesPsr4()['Ucp\\Sdk\\']);
    }

    #[Test]
    public function testTheSdkPrefixesAreAppendedWhenThePluginHasNoBundledSdk(): void
    {
        $projectRoot = $this->composerCopyProject([]);
        $pluginRoot = $projectRoot.'/custom/plugins/SwagAgenticCommerce';
        (new Filesystem())->remove($pluginRoot.'/lib/ucp-php-sdk');
        $loader = new ClassLoader($projectRoot.'/vendor');
        $loader->addPsr4('Ucp\\Sdk\\', $projectRoot.'/vendor/ucp-php-sdk/core/src');

        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $pluginRoot);

        self::assertSame(
            [$projectRoot.'/vendor/ucp-php-sdk/core/src', $pluginRoot.'/lib/ucp-php-sdk/core/src'],
            $loader->getPrefixesPsr4()['Ucp\\Sdk\\'],
        );
    }

    #[Test]
    public function testItReplacesTheClassMapEntriesOfThePluginAndTheSdkCopiesInVendor(): void
    {
        $projectRoot = $this->composerCopyProject(['require' => ['shopware/agentic-commerce' => '1.4.0']]);
        $pluginRoot = $projectRoot.'/custom/plugins/SwagAgenticCommerce';
        $loader = $this->loaderWithVendorCopies($projectRoot);

        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $pluginRoot);

        $classMap = $loader->getClassMap();
        self::assertSame($pluginRoot.'/src/Foo.php', $classMap[self::PLUGIN_CLASS]);
        self::assertSame($pluginRoot.'/lib/ucp-php-sdk/core/src/Enum/Transport.php', $classMap[self::CORE_CLASS]);
        self::assertSame($pluginRoot.'/lib/ucp-php-sdk/symfony-bundle/src/UcpSdkBundle.php', $classMap[self::BUNDLE_CLASS]);
    }

    #[Test]
    public function testItReplacesTheEntriesWhenTheProjectRequiresTheSdkItself(): void
    {
        $projectRoot = $this->composerCopyProject(['require' => [
            'ucp-php-sdk/core' => '0.0.7',
            'ucp-php-sdk/symfony-bundle' => '0.0.7',
        ]]);
        $pluginRoot = $projectRoot.'/custom/plugins/SwagAgenticCommerce';
        $loader = $this->loaderWithVendorCopies($projectRoot);

        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $pluginRoot);

        self::assertSame($pluginRoot.'/lib/ucp-php-sdk/core/src/Enum/Transport.php', $loader->getClassMap()[self::CORE_CLASS]);
    }

    #[Test]
    public function testItLeavesEntriesAloneThatAreNotInTheClassListOrHaveNoFileInThePlugin(): void
    {
        $projectRoot = $this->composerCopyProject([]);
        $loader = $this->loaderWithVendorCopies($projectRoot);

        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $projectRoot.'/custom/plugins/SwagAgenticCommerce');

        $classMap = $loader->getClassMap();
        self::assertSame($projectRoot.'/vendor/shopware/agentic-commerce/src/RemovedInTheArchive.php', $classMap[self::PLUGIN_CLASS_WITHOUT_FILE]);
        self::assertSame($projectRoot.'/vendor/shopware/agentic-commerce/src/Unlisted.php', $classMap[self::UNLISTED_PLUGIN_CLASS]);
        self::assertSame($projectRoot.'/vendor/acme/other/Other.php', $classMap['Acme\\Other']);
    }

    #[Test]
    public function testItKeepsTheClassMapWithoutTheBundledSdk(): void
    {
        $projectRoot = $this->composerCopyProject([]);
        (new Filesystem())->remove($projectRoot.'/custom/plugins/SwagAgenticCommerce/lib/ucp-php-sdk');
        $loader = $this->loaderWithVendorCopies($projectRoot);
        $classMapBeforeRegistration = $loader->getClassMap();

        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $projectRoot.'/custom/plugins/SwagAgenticCommerce');

        self::assertSame($classMapBeforeRegistration, $loader->getClassMap());
    }

    #[Test]
    public function testItKeepsTheClassMapWhenNoCopyOfThePluginOrTheSdkIsInVendor(): void
    {
        $projectRoot = $this->composerCopyProject([]);
        $filesystem = new Filesystem();

        foreach (['shopware/agentic-commerce', 'ucp-php-sdk/core', 'ucp-php-sdk/symfony-bundle'] as $vendorPackage) {
            $filesystem->remove($projectRoot.'/vendor/'.$vendorPackage);
        }

        $loader = $this->loaderWithVendorCopies($projectRoot);
        $classMapBeforeRegistration = $loader->getClassMap();

        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $projectRoot.'/custom/plugins/SwagAgenticCommerce');

        self::assertSame($classMapBeforeRegistration, $loader->getClassMap());
    }

    #[Test]
    public function testItKeepsTheClassMapWithoutAClassList(): void
    {
        $projectRoot = $this->composerCopyProject([]);
        unlink($projectRoot.'/custom/plugins/SwagAgenticCommerce'.BundledClassLoading::CLASS_LIST_FILE);
        $loader = $this->loaderWithVendorCopies($projectRoot);
        $classMapBeforeRegistration = $loader->getClassMap();

        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $projectRoot.'/custom/plugins/SwagAgenticCommerce');

        self::assertSame($classMapBeforeRegistration, $loader->getClassMap());
    }

    #[Test]
    public function testItKeepsTheClassMapWhenTheArchiveItselfIsInstalledInVendor(): void
    {
        $projectRoot = $this->composerCopyProject([]);
        $loader = $this->loaderWithVendorCopies($projectRoot);
        $classMapBeforeRegistration = $loader->getClassMap();
        $pluginRoot = $projectRoot.'/vendor/shopware/agentic-commerce';
        mkdir($pluginRoot.'/lib/ucp-php-sdk', 0o777, true);
        file_put_contents($pluginRoot.BundledClassLoading::CLASS_LIST_FILE, '<?php return '.var_export(['plugin' => [self::PLUGIN_CLASS], 'sdk' => []], true).';');

        BundledClassLoading::registerOn($loader, $projectRoot.'/vendor', $pluginRoot);

        self::assertSame($classMapBeforeRegistration, $loader->getClassMap());
    }

    #[Test]
    public function testItReadsTheClassListsOfTheArchive(): void
    {
        $projectRoot = $this->composerCopyProject([]);

        self::assertSame(
            ['plugin' => [self::PLUGIN_CLASS, self::PLUGIN_CLASS_WITHOUT_FILE], 'sdk' => [self::CORE_CLASS, self::BUNDLE_CLASS]],
            BundledClassLoading::readClassLists($projectRoot.'/custom/plugins/SwagAgenticCommerce'),
        );
    }

    #[Test]
    public function testAMissingOrMalformedClassListReadsAsNull(): void
    {
        $pluginRoot = $this->pluginRoot([]);
        mkdir($pluginRoot.'/lib');

        self::assertNull(BundledClassLoading::readClassLists($pluginRoot));

        file_put_contents($pluginRoot.BundledClassLoading::CLASS_LIST_FILE, '<?php return ["plugin" => "not a list"];');

        self::assertNull(BundledClassLoading::readClassLists($pluginRoot));

        file_put_contents($pluginRoot.BundledClassLoading::CLASS_LIST_FILE, '<?php throw new \RuntimeException();');

        self::assertNull(BundledClassLoading::readClassLists($pluginRoot));
    }

    #[Test]
    public function testAManifestThatIsMissingOrNotJsonReadsAsEmpty(): void
    {
        $pluginRoot = $this->pluginRoot([]);
        unlink($pluginRoot.'/composer.json');

        self::assertSame([], BundledClassLoading::readManifest($pluginRoot));

        file_put_contents($pluginRoot.'/composer.json', '{not json');

        self::assertSame([], BundledClassLoading::readManifest($pluginRoot));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function pluginRoot(array $manifest): string
    {
        $pluginRoot = sys_get_temp_dir().'/swag-agentic-class-loading-'.bin2hex(random_bytes(6));
        mkdir($pluginRoot);
        $this->roots[] = $pluginRoot;
        file_put_contents($pluginRoot.'/composer.json', (string) json_encode($manifest));

        return $pluginRoot;
    }

    /**
     * A project that has the plugin and both SDK packages in `vendor/` and an archive of the plugin in `custom/plugins`.
     *
     * @param array<string, mixed> $projectManifest
     */
    private function composerCopyProject(array $projectManifest): string
    {
        $projectRoot = $this->pluginRoot($projectManifest);
        $pluginRoot = $projectRoot.'/custom/plugins/SwagAgenticCommerce';

        foreach ([
            'src/Foo.php',
            'src/Unlisted.php',
            'lib/ucp-php-sdk/core/src/Enum/Transport.php',
            'lib/ucp-php-sdk/symfony-bundle/src/UcpSdkBundle.php',
        ] as $file) {
            mkdir(\dirname($pluginRoot.'/'.$file), 0o777, true);
            touch($pluginRoot.'/'.$file);
        }

        file_put_contents($pluginRoot.'/composer.json', (string) json_encode([
            'version' => '1.5.0',
            'autoload' => ['psr-4' => [
                'Swag\\AgenticCommerce\\' => 'src/',
                'Ucp\\Sdk\\' => 'lib/ucp-php-sdk/core/src/',
                'Ucp\\Sdk\\Symfony\\' => 'lib/ucp-php-sdk/symfony-bundle/src/',
            ]],
        ]));
        file_put_contents($pluginRoot.BundledClassLoading::CLASS_LIST_FILE, '<?php return '.var_export([
            'plugin' => [self::PLUGIN_CLASS, self::PLUGIN_CLASS_WITHOUT_FILE],
            'sdk' => [self::CORE_CLASS, self::BUNDLE_CLASS],
        ], true).';');

        foreach (['shopware/agentic-commerce/src', 'ucp-php-sdk/core/src/Enum', 'ucp-php-sdk/symfony-bundle/src'] as $directory) {
            mkdir($projectRoot.'/vendor/'.$directory, 0o777, true);
        }

        touch($projectRoot.'/vendor/ucp-php-sdk/core/src/Enum/Transport.php');

        return $projectRoot;
    }

    private function loaderWithVendorCopies(string $projectRoot): ClassLoader
    {
        $loader = new ClassLoader($projectRoot.'/vendor');
        $loader->addClassMap([
            self::PLUGIN_CLASS => $projectRoot.'/vendor/shopware/agentic-commerce/src/Foo.php',
            self::PLUGIN_CLASS_WITHOUT_FILE => $projectRoot.'/vendor/shopware/agentic-commerce/src/RemovedInTheArchive.php',
            self::UNLISTED_PLUGIN_CLASS => $projectRoot.'/vendor/shopware/agentic-commerce/src/Unlisted.php',
            self::CORE_CLASS => $projectRoot.'/vendor/ucp-php-sdk/core/src/Enum/Transport.php',
            self::BUNDLE_CLASS => $projectRoot.'/vendor/ucp-php-sdk/symfony-bundle/src/UcpSdkBundle.php',
            'Acme\\Other' => $projectRoot.'/vendor/acme/other/Other.php',
        ]);

        return $loader;
    }
}
