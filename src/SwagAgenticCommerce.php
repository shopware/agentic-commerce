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
     * Registers the dependencies shipped inside this plugin.
     *
     * Shopware only autoloads a plugin's own `autoload.psr-4` from its composer.json; it never
     * requires `custom/plugins/<Plugin>/vendor/autoload.php`. So when the plugin is installed
     * from a store ZIP -- where the UCP SDK is vendored into the archive rather than resolved by
     * the shop -- every `Ucp\Sdk\...` class is on disk and invisible, and installation fails
     * with SdkNotAvailableException before anything else runs.
     *
     * A shop that installs the plugin through Composer already has the SDK on the project
     * autoloader; there the file is absent and this is a no-op. `require_once` keeps it safe to
     * call from every entry point, and the project's loader stays first, so a Composer-managed
     * SDK still wins over the bundled copy.
     */
    private function registerBundledDependencies(): void
    {
        $autoloader = $this->getPath() . '/vendor/autoload.php';

        if (is_file($autoloader)) {
            require_once $autoloader;
        }
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
        $bundleClass = 'Ucp\\Sdk\\Symfony\\UcpSdkBundle';
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
        return true;
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
