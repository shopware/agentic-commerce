<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Parameter\AdditionalBundleParameters;
use Shopware\Core\Framework\Plugin\KernelPluginCollection;
use Swag\AgenticCommerce\DependencyInjection\AgenticCommerceCoexistenceCompilerPass;
use Swag\AgenticCommerce\DependencyInjection\TestAgentProfileFetcherCompilerPass;
use Swag\AgenticCommerce\SwagAgenticCommerce;
use Swag\AgenticCommerce\Ucp\DependencyInjection\AdvertiseUcpToolsWithoutToolsetPinningPass;
use Swag\AgenticCommerce\Ucp\DependencyInjection\ReplaceSdkSigningKeyCommandsPass;
use Swag\AgenticCommerce\Ucp\DependencyInjection\ReplaceSdkUrlSafetyValidatorPass;
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
}
