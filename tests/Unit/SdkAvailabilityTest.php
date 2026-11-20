<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\SdkAvailability;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
#[CoversClass(SdkAvailability::class)]
final class SdkAvailabilityTest extends TestCase
{
    use RedirectsErrorLog;

    private const BUNDLED_VERSION = '0.0.9';

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        $this->redirectErrorLog();
    }

    protected function tearDown(): void
    {
        $this->restoreErrorLog();
        (new Filesystem())->remove($this->roots);
        $this->roots = [];

        parent::tearDown();
    }

    #[Test]
    public function testTheInstalledSdkThisSuiteRunsAgainstIsUsable(): void
    {
        self::assertNull(SdkAvailability::reason(\dirname(__DIR__, 2)));
        self::assertTrue(SdkAvailability::isUsable(\dirname(__DIR__, 2)));
        self::assertFalse(SdkAvailability::isBundled(\dirname(__DIR__, 2)));
    }

    #[Test]
    public function testAnSdkOlderThanThePinnedOneIsNotUsable(): void
    {
        $pluginRoot = $this->pluginRootRequiring('9.9.9');

        $unavailabilityReason = SdkAvailability::reason($pluginRoot);

        self::assertIsString($unavailabilityReason);
        self::assertStringContainsString('ucp-php-sdk/core', $unavailabilityReason);
        self::assertStringContainsString('9.9.9', $unavailabilityReason);
        self::assertFalse(SdkAvailability::isUsable($pluginRoot));
        self::assertFalse(SdkAvailability::isBundled($pluginRoot));
    }

    #[Test]
    public function testAManifestThatPinsNothingFallsBackToWhatIsInstalled(): void
    {
        // Deliberate: with no constraint to compare against, an SDK that is installed and loadable
        // is accepted rather than refused. The alternative would switch the plugin off over a
        // manifest it could not read, which is a worse failure than the one this guards.
        $pluginRoot = $this->pluginRoot(['acme/unrelated' => '1.0.0']);

        self::assertNull(SdkAvailability::reason($pluginRoot));
    }

    #[Test]
    public function testItLogsWhatToTypeToFixItInADevelopmentCheckout(): void
    {
        $pluginRoot = $this->pluginRootRequiring('9.9.9');

        SdkAvailability::logUnusable($pluginRoot, $pluginRoot);

        $logWithFixInstructions = (string) file_get_contents($pluginRoot.'/swag-agentic-commerce.log');

        self::assertStringContainsString('composer require ucp-php-sdk/core:9.9.9', $logWithFixInstructions);
        self::assertStringContainsString('ucp-php-sdk/symfony-bundle:9.9.9', $logWithFixInstructions);
        self::assertStringContainsString('registers no services, routes or feeds', $logWithFixInstructions);
    }

    #[Test]
    public function testItLogsThatShopwareInstallsNothingForAnArchive(): void
    {
        $archiveRoot = $this->archiveRoot(['replace' => $this->bundledReplace('9.9.9')]);

        SdkAvailability::logUnusable($archiveRoot, $archiveRoot);

        $logForArchive = (string) file_get_contents($archiveRoot.'/swag-agentic-commerce.log');

        self::assertStringContainsString('composer remove ucp-php-sdk/core ucp-php-sdk/symfony-bundle', $logForArchive);
        self::assertStringContainsString('Shopware installs no requirements', $logForArchive);
        self::assertStringNotContainsString('composer require ucp-php-sdk/core:', $logForArchive);
    }

    #[Test]
    public function testItLogsTheCleanupForTheComposerDependencyThatVersion140Left(): void
    {
        $archiveRoot = $this->archiveRoot([]);

        SdkAvailability::logLeftoverComposerDependency($archiveRoot);

        $logWithCleanupSteps = (string) file_get_contents($archiveRoot.'/swag-agentic-commerce.log');

        self::assertStringContainsString('composer remove --no-scripts shopware/agentic-commerce', $logWithCleanupSteps);
        self::assertStringContainsString('bin/console plugin:refresh', $logWithCleanupSteps);
        self::assertStringContainsString('except on a cluster setup', $logWithCleanupSteps);
        self::assertStringContainsString('still requires shopware/agentic-commerce', $this->loggedErrors());
    }

    #[Test]
    public function testItLogsWhyTheRemovalOfTheComposerDependencyFailedAndHowToDoItByHand(): void
    {
        $archiveRoot = $this->archiveRoot([]);

        SdkAvailability::logLeftoverComposerDependencyRemovalFailed('Composer exited with 2', $archiveRoot);

        $logWithRemovalFailure = (string) file_get_contents($archiveRoot.'/swag-agentic-commerce.log');

        self::assertStringContainsString('Removing shopware/agentic-commerce from the shop\'s composer.json failed: Composer exited with 2.', $logWithRemovalFailure);
        self::assertStringContainsString('composer remove --no-scripts shopware/agentic-commerce', $logWithRemovalFailure);
        self::assertStringContainsString('Composer exited with 2', $this->loggedErrors());
    }

    #[Test]
    public function testItWritesToTheErrorLogEvenWithoutALogDirectory(): void
    {
        SdkAvailability::logLeftoverComposerDependencyRemovalFailed('Composer exited with 2', null);

        self::assertStringContainsString('Composer exited with 2', $this->loggedErrors());
    }

    #[Test]
    public function testAnAbsentManifestDoesNotRaise(): void
    {
        $pluginRoot = sys_get_temp_dir().'/swag-agentic-no-manifest-'.bin2hex(random_bytes(6));
        mkdir($pluginRoot);
        $this->roots[] = $pluginRoot;

        // Called while the container is compiling, where throwing is the one thing it must not do.
        self::assertNull(SdkAvailability::reason($pluginRoot));
    }

    #[Test]
    public function testTheBundledCopyIsUsableWithoutAskingComposer(): void
    {
        $archiveRoot = $this->archiveRoot();

        $verdictForBundledCopy = $this->evaluate($archiveRoot, $this->classFilesFromBundle($archiveRoot), static function (): never {
            throw new \LogicException('Composer knows nothing about the bundled copy.');
        });

        self::assertNull($verdictForBundledCopy['reason']);
        self::assertTrue($verdictForBundledCopy['isBundled']);
    }

    #[Test]
    public function testARootCopyAtTheBundledVersionIsUsable(): void
    {
        $archiveRoot = $this->archiveRoot();

        $verdictForRootCopyAtBundledVersion = $this->evaluate($archiveRoot, $this->classFilesFromRootVendor(), $this->installedAt(self::BUNDLED_VERSION));

        self::assertNull($verdictForRootCopyAtBundledVersion['reason']);
        self::assertFalse($verdictForRootCopyAtBundledVersion['isBundled']);
    }

    #[Test]
    public function testARootCopyAtAnotherVersionIsNotUsableAndNamesTheFix(): void
    {
        $archiveRoot = $this->archiveRoot();

        $verdictForRootCopyAtAnotherVersion = $this->evaluate($archiveRoot, $this->classFilesFromRootVendor(), $this->installedAt('0.0.7'));

        self::assertIsString($verdictForRootCopyAtAnotherVersion['reason']);
        self::assertStringContainsString('ucp-php-sdk/core 0.0.7 is installed', $verdictForRootCopyAtAnotherVersion['reason']);
        self::assertStringContainsString('bundles 0.0.9', $verdictForRootCopyAtAnotherVersion['reason']);
        self::assertStringContainsString('composer remove ucp-php-sdk/core ucp-php-sdk/symfony-bundle', $verdictForRootCopyAtAnotherVersion['reason']);
        self::assertStringContainsString('ucp-php-sdk/core:0.0.9 ucp-php-sdk/symfony-bundle:0.0.9', $verdictForRootCopyAtAnotherVersion['reason']);
        self::assertFalse($verdictForRootCopyAtAnotherVersion['isBundled']);
    }

    #[Test]
    public function testTheArchiveUnderComposerCountsAsBundledWhenComposerReportsItReplacesTheSdk(): void
    {
        $archiveRoot = $this->archiveRoot();

        $verdictForArchiveUnderComposer = $this->evaluate($archiveRoot, $this->classFilesFromRootVendor(), static fn (): array => ['prettyVersion' => null]);

        self::assertNull($verdictForArchiveUnderComposer['reason']);
    }

    #[Test]
    public function testARootCopyComposerDoesNotKnowIsNotUsable(): void
    {
        $archiveRoot = $this->archiveRoot();

        $verdictForUnknownRootCopy = $this->evaluate($archiveRoot, $this->classFilesFromRootVendor(), static fn (): ?array => null);

        self::assertIsString($verdictForUnknownRootCopy['reason']);
        self::assertStringContainsString('Composer does not know it', $verdictForUnknownRootCopy['reason']);
    }

    #[Test]
    public function testPackagesLoadedFromDifferentPlacesAreNotUsable(): void
    {
        $archiveRoot = $this->archiveRoot();
        $classFileByPackage = $this->classFilesFromBundle($archiveRoot);
        $classFileByPackage['ucp-php-sdk/symfony-bundle'] = '/shop/vendor/ucp-php-sdk/symfony-bundle/src/UcpSdkBundle.php';

        $verdictForMixedCopies = $this->evaluate($archiveRoot, $classFileByPackage, $this->installedAt(self::BUNDLED_VERSION));

        self::assertIsString($verdictForMixedCopies['reason']);
        self::assertStringContainsString('ucp-php-sdk/core from the bundled copy', $verdictForMixedCopies['reason']);
        self::assertStringContainsString('ucp-php-sdk/symfony-bundle from the shop\'s root vendor/', $verdictForMixedCopies['reason']);
        self::assertStringContainsString('composer remove', $verdictForMixedCopies['reason']);
    }

    #[Test]
    public function testAMissingExtensionOfTheBundledSdkIsNotUsable(): void
    {
        $archiveRoot = $this->archiveRoot();
        file_put_contents($archiveRoot.'/lib/ucp-php-sdk/core/composer.json', (string) json_encode(['require' => ['ext-swag-agentic-missing' => '*', 'ext-json' => '*']]));

        $verdictWithoutBundledSdkExtension = $this->evaluate($archiveRoot, $this->classFilesFromBundle($archiveRoot), $this->installedAt(self::BUNDLED_VERSION));

        self::assertSame('The PHP extension ext-swag-agentic-missing is required by the UCP SDK but not loaded', $verdictWithoutBundledSdkExtension['reason']);
    }

    #[Test]
    public function testAMissingExtensionOfTheManifestIsNotUsable(): void
    {
        $archiveRoot = $this->archiveRoot(['require' => ['ext-swag-agentic-missing' => '*']]);

        $verdictWithoutManifestExtension = $this->evaluate($archiveRoot, $this->classFilesFromBundle($archiveRoot), $this->installedAt(self::BUNDLED_VERSION));

        self::assertStringContainsString('ext-swag-agentic-missing', (string) $verdictWithoutManifestExtension['reason']);
    }

    #[Test]
    public function testNoSdkAtAllIsNotUsable(): void
    {
        $archiveRoot = $this->archiveRoot();

        $verdictWithoutSdk = $this->evaluate($archiveRoot, ['ucp-php-sdk/core' => null, 'ucp-php-sdk/symfony-bundle' => null], $this->installedAt(self::BUNDLED_VERSION));

        self::assertSame('The classes of ucp-php-sdk/core cannot be loaded', $verdictWithoutSdk['reason']);
        self::assertFalse($verdictWithoutSdk['isBundled']);
    }

    #[Test]
    public function testADevelopmentCheckoutComparesTheInstalledVersionWithItsPin(): void
    {
        $pluginRoot = $this->pluginRootRequiring('0.0.9');
        $classFileByPackage = ['ucp-php-sdk/core' => '/shop/vendor/ucp-php-sdk/core/src/Enum/Transport.php', 'ucp-php-sdk/symfony-bundle' => '/shop/vendor/ucp-php-sdk/symfony-bundle/src/UcpSdkBundle.php'];

        $verdictAtPin = $this->evaluate($pluginRoot, $classFileByPackage, $this->installedAt('0.0.9'));
        $verdictBelowPin = $this->evaluate($pluginRoot, $classFileByPackage, $this->installedAt('0.0.7'));

        self::assertNull($verdictAtPin['reason']);
        self::assertFalse($verdictAtPin['isBundled']);
        self::assertSame('ucp-php-sdk/core 0.0.7 is installed, but this version needs 0.0.9', $verdictBelowPin['reason']);
    }

    #[Test]
    public function testADevelopmentCheckoutWithoutTheSdkIsNotUsable(): void
    {
        $pluginRoot = $this->pluginRootRequiring('0.0.9');

        $verdictNotInstalled = $this->evaluate($pluginRoot, ['ucp-php-sdk/core' => null, 'ucp-php-sdk/symfony-bundle' => null], static fn (): ?array => null);
        $verdictNotLoadable = $this->evaluate($pluginRoot, ['ucp-php-sdk/core' => null, 'ucp-php-sdk/symfony-bundle' => null], $this->installedAt('0.0.9'));

        self::assertSame('ucp-php-sdk/core 0.0.9 is required but not installed', $verdictNotInstalled['reason']);
        self::assertSame('ucp-php-sdk/symfony-bundle is installed but its classes cannot be loaded', $verdictNotLoadable['reason']);
    }

    /**
     * @param array<string, string|null> $classFileByPackage
     *
     * @return array{reason: string|null, isBundled: bool}
     */
    private function evaluate(string $pluginRoot, array $classFileByPackage, \Closure $installedPackage): array
    {
        /** @var array{reason: string|null, isBundled: bool} $verdict */
        $verdict = (new \ReflectionMethod(SdkAvailability::class, 'evaluate'))->invoke(null, $pluginRoot, $classFileByPackage, $installedPackage);

        return $verdict;
    }

    /**
     * @return \Closure(string): array{prettyVersion: string|null}
     */
    private function installedAt(string $prettyVersion): \Closure
    {
        return static fn (): array => ['prettyVersion' => $prettyVersion];
    }

    /**
     * @return array<string, string>
     */
    private function classFilesFromBundle(string $archiveRoot): array
    {
        $bundledSdkRoot = (string) realpath($archiveRoot.'/lib/ucp-php-sdk');

        return [
            'ucp-php-sdk/core' => $bundledSdkRoot.'/core/src/Enum/Transport.php',
            'ucp-php-sdk/symfony-bundle' => $bundledSdkRoot.'/symfony-bundle/src/UcpSdkBundle.php',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function classFilesFromRootVendor(): array
    {
        return [
            'ucp-php-sdk/core' => '/shop/vendor/ucp-php-sdk/core/src/Enum/Transport.php',
            'ucp-php-sdk/symfony-bundle' => '/shop/vendor/ucp-php-sdk/symfony-bundle/src/UcpSdkBundle.php',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function bundledReplace(string $version): array
    {
        return ['ucp-php-sdk/core' => $version, 'ucp-php-sdk/symfony-bundle' => $version];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function archiveRoot(array $manifest = []): string
    {
        $pluginRoot = $this->pluginRoot([], $manifest + ['replace' => $this->bundledReplace(self::BUNDLED_VERSION)]);

        foreach (['core', 'symfony-bundle'] as $package) {
            mkdir($pluginRoot.'/lib/ucp-php-sdk/'.$package.'/src', 0o777, true);
        }

        return $pluginRoot;
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
     * @param array<string, mixed>  $manifest
     */
    private function pluginRoot(array $require, array $manifest = []): string
    {
        $pluginRoot = sys_get_temp_dir().'/swag-agentic-sdk-availability-'.bin2hex(random_bytes(6));
        mkdir($pluginRoot);
        $this->roots[] = $pluginRoot;

        if ([] !== $require) {
            $manifest['require'] = $require;
        }

        file_put_contents($pluginRoot.'/composer.json', (string) json_encode($manifest));

        return $pluginRoot;
    }
}
