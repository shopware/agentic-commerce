<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Exception\SdkNotAvailableException;
use Swag\AgenticCommerce\SwagAgenticCommerce;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * The archive ships no dependencies, so Shopware has to install them.
 *
 * `executeComposerCommands()` is what makes it run `composer require shopware/agentic-commerce`
 * against the project on a zip install, which resolves the extracted archive through the
 * `custom/plugins/*` path repository and pulls the pinned SDK from Packagist. 1.3.0 returned false
 * here and vendored the SDK into the plugin instead, which left a second Composer ClassLoader in
 * every shop that installed it. Both halves are asserted: either one alone leaves the plugin
 * without an SDK at runtime.
 *
 * @internal
 */
#[CoversClass(SwagAgenticCommerce::class)]
final class SwagAgenticCommerceTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            @unlink($root.'/composer.json');
            @rmdir($root);
        }

        $this->roots = [];

        parent::tearDown();
    }

    #[Test]
    public function testItLetsShopwareInstallComposerDependencies(): void
    {
        $plugin = new SwagAgenticCommerce(true, \dirname(__DIR__, 2));

        self::assertTrue($plugin->executeComposerCommands());
    }

    /**
     * `PluginLifecycleService::executeComposerRequireWhenNeeded()` returns early on a cluster
     * setup, so the requirements are never resolved. Installing anyway would report success and
     * leave an extension that does nothing -- the one case where refusing is the kinder answer.
     */
    #[Test]
    public function testAClusterSetupRefusesTheInstallRatherThanInstallingNothing(): void
    {
        $plugin = $this->pluginWithoutSdk(true);

        $this->expectException(SdkNotAvailableException::class);
        $this->expectExceptionMessageMatches('/cluster_setup/');

        $this->refuse($plugin);
    }

    #[Test]
    public function testAnOrdinaryShopIsLeftToComposerInstead(): void
    {
        // Everywhere else the missing SDK is the window between extraction and `composer require`,
        // which closes by itself; refusing would turn that into a failed install.
        $this->refuse($this->pluginWithoutSdk(false));
        $this->refuse($this->pluginWithoutSdk(null));

        $this->expectNotToPerformAssertions();
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

    private function refuse(SwagAgenticCommerce $plugin): void
    {
        (new \ReflectionMethod($plugin, 'refuseWhenNothingWillInstallTheSdk'))->invoke($plugin);
    }

    /**
     * @param bool|null $clusterSetup null leaves the parameter undefined, as an older shop would
     */
    private function pluginWithoutSdk(?bool $clusterSetup): SwagAgenticCommerce
    {
        $root = sys_get_temp_dir().'/swag-agentic-cluster-'.bin2hex(random_bytes(6));
        mkdir($root);
        $this->roots[] = $root;
        file_put_contents($root.'/composer.json', (string) json_encode(['require' => [
            'ucp-php-sdk/core' => '9.9.9',
            'ucp-php-sdk/symfony-bundle' => '9.9.9',
        ]]));

        $plugin = new SwagAgenticCommerce(true, $root);

        if (null !== $clusterSetup) {
            $plugin->setContainer(new Container(new ParameterBag([
                'shopware.deployment.cluster_setup' => $clusterSetup,
            ])));
        }

        return $plugin;
    }
}
