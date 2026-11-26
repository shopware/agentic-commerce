<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Parameter\AdditionalBundleParameters;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\KernelPluginCollection;
use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;
use Swag\AgenticCommerce\DependencyInjection\AgenticCommerceCoexistenceCompilerPass;
use Swag\AgenticCommerce\DependencyInjection\TestAgentProfileFetcherCompilerPass;
use Swag\AgenticCommerce\Exception\PluginFilesReplacedException;
use Swag\AgenticCommerce\SwagAgenticCommerce;
use Swag\AgenticCommerce\Tests\Unit\UpdateProtection\ClassLoadRecorder;
use Swag\AgenticCommerce\Tests\Unit\UpdateProtection\TemporaryPluginRoot;
use Swag\AgenticCommerce\Ucp\DependencyInjection\AdvertiseUcpToolsWithoutToolsetPinningPass;
use Swag\AgenticCommerce\Ucp\DependencyInjection\ReplaceSdkSigningKeyCommandsPass;
use Swag\AgenticCommerce\Ucp\DependencyInjection\ReplaceSdkUrlSafetyValidatorPass;
use Swag\AgenticCommerce\UpdateProtection\ClassPreloader;
use Swag\AgenticCommerce\UpdateProtection\PendingSdkSchema;
use Swag\AgenticCommerce\UpdateProtection\UpdateProtectionSubscriber;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
#[CoversClass(SwagAgenticCommerce::class)]
final class SwagAgenticCommerceTest extends TestCase
{
    use RedirectsErrorLog;

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        $this->redirectErrorLog();
        $this->preload([], []);
    }

    protected function tearDown(): void
    {
        $this->restoreErrorLog();
        (new Filesystem())->remove($this->roots);
        $this->roots = [];

        parent::tearDown();
    }

    #[Test]
    public function testADevelopmentCheckoutLetsShopwareInstallTheSdkThroughComposer(): void
    {
        $plugin = new SwagAgenticCommerce(true, \dirname(__DIR__, 2));

        self::assertDirectoryDoesNotExist(\dirname(__DIR__, 2).'/lib');
        self::assertTrue($plugin->executeComposerCommands());
    }

    #[Test]
    public function testAnArchiveWithTheBundledSdkNeedsNoComposerRun(): void
    {
        $pluginRoot = $this->temporaryDirectoryWithManifest(['version' => '1.5.0']);
        mkdir($pluginRoot.'/lib/ucp-php-sdk/core/src', 0o777, true);

        self::assertFalse((new SwagAgenticCommerce(true, $pluginRoot))->executeComposerCommands());
    }

    #[Test]
    public function testTheManifestRequiresTheSdkComposerHasToResolve(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 2).'/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR
        );

        self::assertIsArray($manifest);
        $require = $manifest['require'] ?? null;
        self::assertIsArray($require);

        foreach (['ucp-php-sdk/core', 'ucp-php-sdk/symfony-bundle'] as $package) {
            self::assertArrayHasKey($package, $require, \sprintf('%s has to stay required.', $package));
            self::assertMatchesRegularExpression(
                '/^\d+\.\d+\.\d+$/',
                (string) $require[$package],
                'An exact version is the only constraint that cannot hand a shop an SDK we never tested.',
            );
        }
    }

    #[Test]
    public function testAnUpdateAfterTheFilesWereReplacedBeforeTheProcessStartedIsNotStopped(): void
    {
        $pluginRoot = $this->temporaryDirectoryWithManifest(['version' => '1.5.0']);
        file_put_contents($pluginRoot.'/composer.json', (string) json_encode(['version' => '1.6.0']));
        $plugin = new SwagAgenticCommerce(true, $pluginRoot);

        $plugin->postUpdate($this->createStub(UpdateContext::class));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testALifecycleCallStopsWhenAnotherVersionReplacedTheFilesInTheRunningProcess(): void
    {
        $pluginRoot = $this->temporaryDirectoryWithManifest(['version' => '1.5.0']);
        $plugin = new SwagAgenticCommerce(true, $pluginRoot);
        file_put_contents($pluginRoot.'/composer.json', (string) json_encode(['version' => '1.6.0']));

        $this->expectException(PluginFilesReplacedException::class);
        $this->expectExceptionMessageMatches(
            '/replaced by version 1\.6\.0 while this process was running version 1\.5\.0.*new process.*'
            .'`bin\/console plugin:update SwagAgenticCommerce`.*`bin\/console plugin:activate SwagAgenticCommerce`/s'
        );

        $plugin->postUpdate($this->createStub(UpdateContext::class));
    }

    #[Test]
    public function testALifecycleCallAfterThePreloadOfEveryClassIsNotStoppedByTheReplacedFiles(): void
    {
        $pluginRoot = $this->temporaryDirectoryWithManifest(['version' => '1.5.0']);
        $plugin = new SwagAgenticCommerce(true, $pluginRoot);
        file_put_contents($pluginRoot.'/composer.json', (string) json_encode(['version' => '1.6.0']));
        $this->preload([\stdClass::class], []);

        $plugin->postUpdate($this->createStub(UpdateContext::class));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testALifecycleCallStopsWhenThePreloadFailedOnAClass(): void
    {
        $pluginRoot = $this->temporaryDirectoryWithManifest(['version' => '1.5.0']);
        $plugin = new SwagAgenticCommerce(true, $pluginRoot);
        file_put_contents($pluginRoot.'/composer.json', (string) json_encode(['version' => '1.6.0']));
        $incompatibleClass = ClassLoadRecorder::className('IncompatibleClass');
        $this->preload([$incompatibleClass], [], [$incompatibleClass]);

        $this->expectException(PluginFilesReplacedException::class);

        $plugin->postUpdate($this->createStub(UpdateContext::class));
    }

    #[Test]
    public function testTheServiceConfigKeepsTheUpdateProtectionDefinitions(): void
    {
        $container = $this->containerBuilder($this->temporaryDirectoryWithManifest([]), $this->temporaryDirectoryWithManifest([]));

        (new SwagAgenticCommerce(true, \dirname(__DIR__, 2)))->build($container);

        foreach ([ClassPreloader::class, UpdateProtectionSubscriber::class] as $serviceClass) {
            $definition = $container->getDefinition($serviceClass);
            self::assertFalse($definition->isAutowired(), $serviceClass);
            self::assertNotSame([], $definition->getArguments(), $serviceClass);
        }
    }

    #[Test]
    public function testThePathOfAnInstanceBuiltWithoutTheConstructorCanBeRead(): void
    {
        $pluginWithoutConstructor = (new \ReflectionClass(SwagAgenticCommerce::class))->newInstanceWithoutConstructor();

        self::assertSame(\dirname(__DIR__, 2).'/src', $pluginWithoutConstructor->getPath());
    }

    #[Test]
    public function testAnUnusableSdkLeavesTheExtensionWithoutBundles(): void
    {
        $plugin = new SwagAgenticCommerce(true, $this->archiveRootWithAnotherSdkVersion());

        $bundles = $plugin->getAdditionalBundles(new AdditionalBundleParameters(new ClassLoader(), new KernelPluginCollection(), []));

        self::assertSame([], $bundles);
    }

    #[Test]
    public function testAnUnusableSdkLeavesTheExtensionWithoutCompilerPassesAndSaysWhy(): void
    {
        $pluginRoot = $this->archiveRootWithAnotherSdkVersion();
        $logsDir = $this->temporaryDirectoryWithManifest([]);
        $container = $this->containerBuilder($logsDir, $this->temporaryDirectoryWithManifest([]));

        (new SwagAgenticCommerce(true, $pluginRoot))->build($container);

        $sdkPassClasses = [
            AgenticCommerceCoexistenceCompilerPass::class,
            ReplaceSdkSigningKeyCommandsPass::class,
            ReplaceSdkUrlSafetyValidatorPass::class,
            AdvertiseUcpToolsWithoutToolsetPinningPass::class,
            TestAgentProfileFetcherCompilerPass::class,
        ];

        foreach ($container->getCompilerPassConfig()->getPasses() as $pass) {
            self::assertNotContains($pass::class, $sdkPassClasses);
        }

        self::assertStringContainsString('The extension is inactive', (string) file_get_contents($logsDir.'/swag-agentic-commerce.log'));
    }

    #[Test]
    public function testTheBuildLogsTheLeftoverRequirementOnlyWhileTheProjectStillRequiresThePlugin(): void
    {
        $pluginRoot = $this->archiveRootWithAnotherSdkVersion();
        $logsDirWithRequirement = $this->temporaryDirectoryWithManifest([]);
        $logsDirWithoutRequirement = $this->temporaryDirectoryWithManifest([]);

        (new SwagAgenticCommerce(true, $pluginRoot))->build($this->containerBuilder(
            $logsDirWithRequirement,
            $this->temporaryDirectoryWithManifest(['require' => ['shopware/agentic-commerce' => '1.4.0']]),
        ));
        (new SwagAgenticCommerce(true, $pluginRoot))->build($this->containerBuilder($logsDirWithoutRequirement, $this->temporaryDirectoryWithManifest([])));

        self::assertStringContainsString('still requires shopware/agentic-commerce', (string) file_get_contents($logsDirWithRequirement.'/swag-agentic-commerce.log'));
        self::assertStringNotContainsString('still requires shopware/agentic-commerce', (string) file_get_contents($logsDirWithoutRequirement.'/swag-agentic-commerce.log'));
    }

    #[Test]
    public function testAnUpdateOnACoreThatRemovesTheRequirementItselfLeavesTheProjectAlone(): void
    {
        $pluginRoot = $this->archiveRootWithAnotherSdkVersion();
        $projectRoot = $this->temporaryDirectoryWithManifest(['require' => ['shopware/agentic-commerce' => '1.4.0']]);
        $container = $this->containerBuilder($this->temporaryDirectoryWithManifest([]), $projectRoot);
        $container->set(AgenticFilesCoreBridgeInterface::class, $this->createStub(AgenticFilesCoreBridgeInterface::class));
        $plugin = new SwagAgenticCommerce(true, $pluginRoot);
        $plugin->setContainer($container);
        $updateContext = $this->createStub(UpdateContext::class);
        $updateContext->method('getCurrentShopwareVersion')->willReturn('6.7.0.0');

        $plugin->update($updateContext);

        self::assertStringContainsString('shopware/agentic-commerce', (string) file_get_contents($projectRoot.'/composer.json'));
    }

    #[Test]
    public function testAnUpdateOnACoreThatKeepsTheRequirementRemovesItThroughComposer(): void
    {
        $pluginRoot = $this->archiveRootWithAnotherSdkVersion();
        $projectRoot = $this->temporaryDirectoryWithManifest([
            'require' => ['shopware/agentic-commerce' => '1.4.0'],
            'repositories' => [['packagist.org' => false]],
        ]);
        $container = $this->containerBuilder($this->temporaryDirectoryWithManifest([]), $projectRoot);
        $container->set(AgenticFilesCoreBridgeInterface::class, $this->createStub(AgenticFilesCoreBridgeInterface::class));
        $plugin = new SwagAgenticCommerce(true, $pluginRoot);
        $plugin->setContainer($container);
        $updateContext = $this->createStub(UpdateContext::class);
        $updateContext->method('getCurrentShopwareVersion')->willReturn('6.6.10.3');
        $workingDirectoryBeforeUpdate = (string) getcwd();

        try {
            $plugin->update($updateContext);
        } finally {
            chdir($workingDirectoryBeforeUpdate);
        }

        self::assertStringNotContainsString('shopware/agentic-commerce', (string) file_get_contents($projectRoot.'/composer.json'));
        self::assertStringNotContainsString('failed', $this->loggedErrors());
    }

    #[Test]
    public function testAnUpdateLeavesTheSdkSchemaPendingForTheNextProcess(): void
    {
        $projectRoot = $this->temporaryDirectoryWithManifest([]);
        $container = $this->containerBuilder($this->temporaryDirectoryWithManifest([]), $projectRoot);
        $container->set(AgenticFilesCoreBridgeInterface::class, $this->createStub(AgenticFilesCoreBridgeInterface::class));
        $plugin = new SwagAgenticCommerce(true, $this->archiveRootWithAnotherSdkVersion());
        $plugin->setContainer($container);
        $updateContext = $this->createStub(UpdateContext::class);
        $updateContext->method('getCurrentShopwareVersion')->willReturn('6.7.0.0');

        $plugin->update($updateContext);

        self::assertFileExists($projectRoot.'/var/swag-agentic-commerce-sdk-schema-pending');
    }

    #[Test]
    public function testABuildWithoutAUsableSdkKeepsTheSdkSchemaPending(): void
    {
        $projectRoot = $this->temporaryDirectoryWithManifest([]);
        PendingSdkSchema::markIn($projectRoot);

        (new SwagAgenticCommerce(true, $this->archiveRootWithAnotherSdkVersion()))
            ->build($this->containerBuilder($this->temporaryDirectoryWithManifest([]), $projectRoot));

        self::assertFileExists($projectRoot.'/var/swag-agentic-commerce-sdk-schema-pending');
    }

    #[Test]
    public function testABuildWithoutPendingSdkSchemaLeavesTheProjectDirectoryAlone(): void
    {
        $projectRoot = $this->temporaryDirectoryWithManifest([]);

        (new SwagAgenticCommerce(true, \dirname(__DIR__, 2)))
            ->build($this->containerBuilder($this->temporaryDirectoryWithManifest([]), $projectRoot));

        self::assertDirectoryDoesNotExist($projectRoot.'/var');
        self::assertSame('', $this->loggedErrors());
    }

    #[Test]
    public function testALifecycleCallStopsWhenTheManifestIsGoneFromTheRunningProcess(): void
    {
        $pluginRoot = $this->temporaryDirectoryWithManifest(['version' => '1.5.0']);
        $plugin = new SwagAgenticCommerce(true, $pluginRoot);
        unlink($pluginRoot.'/composer.json');

        $this->expectException(PluginFilesReplacedException::class);
        $this->expectExceptionMessageMatches('/version unknown/');

        $plugin->postUpdate($this->createStub(UpdateContext::class));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function temporaryDirectoryWithManifest(array $manifest): string
    {
        $pluginRoot = sys_get_temp_dir().'/swag-agentic-plugin-'.bin2hex(random_bytes(6));
        mkdir($pluginRoot);
        $this->roots[] = $pluginRoot;
        file_put_contents($pluginRoot.'/composer.json', (string) json_encode($manifest, \JSON_UNESCAPED_SLASHES));

        return $pluginRoot;
    }

    private function archiveRootWithAnotherSdkVersion(): string
    {
        $pluginRoot = $this->temporaryDirectoryWithManifest([
            'version' => '1.5.0',
            'replace' => ['ucp-php-sdk/core' => '9.9.9', 'ucp-php-sdk/symfony-bundle' => '9.9.9'],
        ]);

        foreach (['core', 'symfony-bundle'] as $package) {
            mkdir($pluginRoot.'/lib/ucp-php-sdk/'.$package.'/src', 0o777, true);
        }

        return $pluginRoot;
    }

    private function containerBuilder(string $logsDir, string $projectDir): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.logs_dir' => $logsDir,
            'kernel.project_dir' => $projectDir,
            'kernel.environment' => 'test',
        ]));

        $container->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'ucp_sdk';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });

        return $container;
    }

    /**
     * @param list<string> $pluginClassNames
     * @param list<string> $sdkClassNames
     * @param list<string> $incompatibleClassNames
     */
    private function preload(array $pluginClassNames, array $sdkClassNames, array $incompatibleClassNames = []): void
    {
        $pluginRoot = TemporaryPluginRoot::create();
        $recorder = new ClassLoadRecorder($pluginRoot->markerPath(), [], $incompatibleClassNames);

        if ([] !== $pluginClassNames || [] !== $sdkClassNames) {
            $pluginRoot->writeClassLists($pluginClassNames, $sdkClassNames);
        }

        (new ClassPreloader($pluginRoot->path(), $pluginRoot->markerPath()))->preload(true);

        $recorder->stop();
        $pluginRoot->remove();
    }
}
