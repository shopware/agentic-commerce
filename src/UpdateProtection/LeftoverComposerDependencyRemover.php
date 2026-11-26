<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\UpdateProtection;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Composer\CommandExecutor;
use Swag\AgenticCommerce\BundledClassLoading;
use Swag\AgenticCommerce\SdkAvailability;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Removes the `shopware/agentic-commerce` requirement that version 1.4.0 added to the shop's composer.json, on the
 * Shopware versions whose core does not do it during the update.
 *
 * It never throws, because an exception in `update()` makes core deactivate the plugin.
 *
 * @internal
 */
#[Package('framework')]
final class LeftoverComposerDependencyRemover
{
    public const COMPOSER_PACKAGE = 'shopware/agentic-commerce';

    private const FIRST_CORE_VERSION_THAT_REMOVES_IT = '6.6.10.4';

    private const CLUSTER_SETUP_PARAMETER = 'shopware.deployment.cluster_setup';

    /**
     * @param \Closure(string): void $removeRequirement receives the project directory and throws when Composer fails
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $pluginRoot,
        private readonly \Closure $removeRequirement,
        private readonly bool $isCli,
    ) {
    }

    public static function withComposer(ContainerInterface $container, string $pluginRoot): self
    {
        return new self(
            $container,
            $pluginRoot,
            static function (string $projectDir): void {
                (new CommandExecutor($projectDir))->remove(self::COMPOSER_PACKAGE, UpdateProtectionSubscriber::PLUGIN_TECHNICAL_NAME);
            },
            \PHP_SAPI === 'cli',
        );
    }

    /**
     * A development checkout and a plugin installed in `vendor/` are required through Composer on purpose.
     */
    public static function isLeftoverIn(string $projectDir, string $pluginRoot): bool
    {
        if (!is_dir($pluginRoot.'/'.BundledClassLoading::BUNDLED_SDK_DIRECTORY)
            || str_starts_with($pluginRoot.'/', $projectDir.'/vendor/')) {
            return false;
        }

        $projectManifest = BundledClassLoading::readManifest($projectDir);

        foreach (['require', 'require-dev'] as $section) {
            if (\is_array($projectManifest[$section] ?? null) && isset($projectManifest[$section][self::COMPOSER_PACKAGE])) {
                return true;
            }
        }

        return false;
    }

    public function removeWhenCoreDoesNot(string $shopwareVersion): void
    {
        $projectDir = $this->parameter('kernel.project_dir');

        if (version_compare($shopwareVersion, self::FIRST_CORE_VERSION_THAT_REMOVES_IT, '>=')
            || true === $this->parameter(self::CLUSTER_SETUP_PARAMETER)
            || !\is_string($projectDir)
            || !self::isLeftoverIn($projectDir, $this->pluginRoot)) {
            return;
        }

        if ($this->isCli) {
            $this->remove($projectDir);

            return;
        }

        $eventDispatcher = $this->container->get('event_dispatcher');

        if (!$eventDispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $hasRun = false;

        $eventDispatcher->addListener(KernelEvents::RESPONSE, function (ResponseEvent $event) use (&$hasRun, $projectDir): void {
            if ($hasRun || !$event->isMainRequest()) {
                return;
            }

            $hasRun = true;
            $this->remove($projectDir);
        }, \PHP_INT_MAX);
    }

    private function remove(string $projectDir): void
    {
        try {
            ($this->removeRequirement)($projectDir);
            $this->markThePluginAsNotManagedByComposer();
        } catch (\Throwable $error) {
            $logsDir = $this->parameter('kernel.logs_dir');

            SdkAvailability::logLeftoverComposerDependencyRemovalFailed(
                $error->getMessage(),
                \is_string($logsDir) ? $logsDir : null,
            );
        }
    }

    /**
     * Core loads a plugin marked as managed by Composer only through Composer's class map, which no longer lists it
     * after the removal, so the next kernel would be built without the plugin. From 6.6.10.4 on, core resets the flag
     * in `PluginService::refreshPlugins()` after its own removal; that service is not public on 6.5.
     */
    private function markThePluginAsNotManagedByComposer(): void
    {
        $pluginRepository = $this->container->has('plugin.repository') ? $this->container->get('plugin.repository') : null;

        if (!$pluginRepository instanceof EntityRepository) {
            return;
        }

        $context = Context::createDefaultContext();
        $criteria = (new Criteria())->addFilter(new EqualsFilter('name', UpdateProtectionSubscriber::PLUGIN_TECHNICAL_NAME));
        $pluginId = $pluginRepository->searchIds($criteria, $context)->firstId();

        if (null === $pluginId) {
            return;
        }

        $pluginRepository->update([['id' => $pluginId, 'managedByComposer' => false]], $context);
    }

    private function parameter(string $name): mixed
    {
        return $this->container->hasParameter($name) ? $this->container->getParameter($name) : null;
    }
}
