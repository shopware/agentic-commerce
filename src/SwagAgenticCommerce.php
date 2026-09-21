<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Parameter\AdditionalBundleParameters;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Kernel;
use Swag\AgenticCommerce\AgenticFiles\AgenticFilesCoreBridgeInterface;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileBridge;
use Swag\AgenticCommerce\AgenticFiles\CoreSalesChannelFileFeature;
use Swag\AgenticCommerce\AgenticFiles\Fallback\AgenticFilesFallbackBundle;
use Swag\AgenticCommerce\DependencyInjection\AgenticCommerceCoexistenceCompilerPass;
use Swag\AgenticCommerce\DependencyInjection\TestAgentProfileFetcherCompilerPass;
use Swag\AgenticCommerce\Exception\SdkNotAvailableException;
use Swag\AgenticCommerce\Ucp\DependencyInjection\ReplaceSdkSigningKeyCommandsPass;
use Swag\AgenticCommerce\Ucp\DependencyInjection\ReplaceSdkUrlSafetyValidatorPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

/** @internal */
#[Package('framework')]
final class SwagAgenticCommerce extends Plugin
{
    /**
     * Mirror of Shopware\Core\Defaults::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE in 6.7.10+.
     * Stable UUID shared across all versions so sales channels survive plugin/core transitions.
     */
    public const SALES_CHANNEL_TYPE_AGENTIC_COMMERCE = '5e29f9890c4d4d519a1c7f9d5c24b7c1';

    public const OPEN_AI_PRODUCT_EXPORT_CONFIG_DOMAIN = 'SwagAgenticCommerce.openAiProductExport';

    public const GOOGLE_PRODUCT_EXPORT_CONFIG_DOMAIN = 'SwagAgenticCommerce.googleProductExport';

    /** Mirror of ProductExportEntity::FILE_FORMAT_JSONL in 6.7.10+. */
    public const FILE_FORMAT_JSONL = 'jsonl';

    /**
     * The SDK bundle Shopware registers. A string, not an imported class: whether it can be
     * resolved at all is precisely what registerBundledDependencies() has to establish.
     */
    private const SDK_BUNDLE_CLASS = 'Ucp\\Sdk\\Symfony\\UcpSdkBundle';

    /**
     * Makes the dependencies shipped inside this plugin loadable.
     *
     * Shopware registers a plugin's own `autoload.psr-4` (KernelPluginLoader::registerPluginNamespaces),
     * and the store archive declares the bundled UCP SDK there -- so by the time this runs the host's
     * class loader normally resolves `Ucp\Sdk\...` already and there is nothing to do. Two cases are
     * left over:
     *
     * - Those prefixes are read from the `plugin.autoload` database column, and `plugin:install -r`
     *   and `plugin:update-all` refresh that column inside a kernel that is already booted. In that
     *   one process the archive's prefixes are a boot behind, so they are registered here instead.
     * - A development lane vendors the SDK into the plugin's own `vendor/` and relies on the
     *   autoloader Composer generates there.
     *
     * The store archive deliberately ships no `vendor/autoload.php`. Requiring one registers a second
     * Composer ClassLoader, which then shows up in Composer's runtime registry
     * (`InstalledVersions::getAllRawData()`) alongside the shop's own and can shadow its package
     * versions. Registering a plain closure keeps the first case out of that registry too.
     */
    private function registerBundledDependencies(): void
    {
        if (class_exists(self::SDK_BUNDLE_CLASS)) {
            return;
        }

        $this->registerBundledNamespaces();

        if (class_exists(self::SDK_BUNDLE_CLASS)) {
            return;
        }

        $this->requireBundledAutoloader();
    }

    /**
     * Loads the autoloader Composer generated inside the plugin, if one is there.
     *
     * Only a development lane has one: it installs the SDK into the plugin's own `vendor/` so a
     * project-level `composer update` cannot drop it again. The store archive ships none, on
     * purpose -- see registerBundledDependencies().
     */
    private function requireBundledAutoloader(): void
    {
        // getBasePath(), not getPath(): Shopware sets a plugin's path to the directory of its
        // plugin class (<plugin>/src), while the vendor directory sits at the plugin root.
        $autoloader = $this->getBasePath().'/vendor/autoload.php';

        if (is_file($autoloader)) {
            require_once $autoloader;
        }
    }

    /**
     * Registers every `autoload.psr-4` prefix the plugin's composer.json declares besides its own --
     * that is, the bundled SDK -- on an autoloader of this plugin's own making.
     */
    private function registerBundledNamespaces(): void
    {
        $prefixes = $this->bundledPsr4Prefixes();

        if ([] === $prefixes) {
            return;
        }

        spl_autoload_register(static function (string $class) use ($prefixes): void {
            foreach ($prefixes as $namespace => $directories) {
                if (!str_starts_with($class, $namespace)) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($class, \strlen($namespace))).'.php';

                foreach ($directories as $directory) {
                    if (is_file($directory.$relative)) {
                        require_once $directory.$relative;

                        return;
                    }
                }
            }
        });
    }

    /**
     * @return array<string, non-empty-list<string>>
     */
    private function bundledPsr4Prefixes(): array
    {
        $manifest = $this->getBasePath().'/composer.json';

        if (!is_file($manifest)) {
            return [];
        }

        $contents = file_get_contents($manifest);

        if (false === $contents) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $autoload = $decoded['autoload'] ?? null;
        $psr4 = \is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;

        if (!\is_array($psr4)) {
            return [];
        }

        $prefixes = [];

        foreach ($psr4 as $namespace => $paths) {
            if (!\is_string($namespace) || str_starts_with($namespace, __NAMESPACE__.'\\')) {
                continue;
            }

            $directories = [];

            foreach (\is_array($paths) ? $paths : [$paths] as $path) {
                if (\is_string($path) && '' !== $path) {
                    $directories[] = rtrim($this->getBasePath().'/'.ltrim($path, '/'), '/').'/';
                }
            }

            if ([] !== $directories) {
                $prefixes[$namespace] = $directories;
            }
        }

        return $prefixes;
    }

    public function build(ContainerBuilder $container): void
    {
        $this->registerBundledDependencies();
        parent::build($container);

        $container->addCompilerPass(
            new AgenticCommerceCoexistenceCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            1000,
        );

        // Runs before the console command-loader pass so the SDK's generic
        // signing-key commands are gone by the time command names are mapped,
        // leaving the plugin's sales-channel-aware subclasses in their place.
        $container->addCompilerPass(
            new ReplaceSdkSigningKeyCommandsPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            10000,
        );

        // Build the SDK's URL-safety validator from the plugin's per-channel/global
        // allowlists instead of the SDK bundle's static (empty) semantic config, so
        // configured remote profile hosts are actually fetchable.
        $container->addCompilerPass(new ReplaceSdkUrlSafetyValidatorPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);

        // In the test environment, swap the SDK's HTTP agent-profile fetcher for a fixed,
        // test-supplied one so the functional suite can negotiate the UCP handshake offline.
        $container->addCompilerPass(
            new TestAgentProfileFetcherCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            1000,
        );
    }

    /**
     * @return list<Bundle>
     */
    public function getAdditionalBundles(AdditionalBundleParameters $parameters): array
    {
        $this->registerBundledDependencies();
        $bundleClass = self::SDK_BUNDLE_CLASS;
        if (!class_exists($bundleClass)) {
            throw SdkNotAvailableException::bundleCouldNotBeLoaded();
        }

        /** @var list<Bundle> $bundles */
        $bundles = [new $bundleClass()];

        if (!CoreSalesChannelFileFeature::isAvailableByClass()) {
            $bundles[] = new AgenticFilesFallbackBundle();
        }

        return $bundles;
    }

    public function install(InstallContext $installContext): void
    {
        $this->registerBundledDependencies();
        parent::install($installContext);

        $this->bootstrapSdkSchema();
        $this->syncCoreAgenticFiles();
    }

    public function update(UpdateContext $updateContext): void
    {
        $this->registerBundledDependencies();
        parent::update($updateContext);

        $this->bootstrapSdkSchema();
        $this->syncCoreAgenticFiles();
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->registerBundledDependencies();
        parent::activate($activateContext);

        $this->syncCoreAgenticFiles();
    }

    public function executeComposerCommands(): bool
    {
        // A packaged SDK is already complete. Re-resolving it would discard the version
        // selected at build time, and cannot resolve an untagged QA build from Packagist.
        return !is_file($this->getBasePath().'/.swag-agentic-commerce-bundled-sdk');
    }

    /**
     * @return array<string, list<string>>
     */
    public function enrichPrivileges(): array
    {
        return [
            'ucp.viewer' => ['system_config:read', 'sales_channel:read', 'sales_channel_domain:read'],
            'ucp.editor' => ['ucp.viewer', 'system_config:update'],
            'ucp.key_rotator' => ['ucp.viewer'],
        ];
    }

    private function syncCoreAgenticFiles(): void
    {
        // install() runs before the plugin's services exist; from activate() on the container
        // has them, and only then can a decorated type resolver be honoured.
        if (isset($this->container) && $this->container->has(AgenticFilesCoreBridgeInterface::class)) {
            $bridge = $this->container->get(AgenticFilesCoreBridgeInterface::class);
            if ($bridge instanceof AgenticFilesCoreBridgeInterface) {
                $bridge->syncActiveUcpSalesChannels();

                return;
            }
        }

        CoreSalesChannelFileBridge::syncActiveUcpSalesChannelsWithConnection(Kernel::getConnection());
    }

    private function bootstrapSdkSchema(): void
    {
        if (!class_exists(SchemaBootstrapper::class)) {
            throw SdkNotAvailableException::bundleCouldNotBeLoaded();
        }

        // The plugin is not active during install yet, so SDK services are not wired into the container.
        // Build the bootstrapper directly and keep request handling free from schema checks.
        (new SchemaBootstrapper(Kernel::getConnection()))->ensureSchema();
    }
}
