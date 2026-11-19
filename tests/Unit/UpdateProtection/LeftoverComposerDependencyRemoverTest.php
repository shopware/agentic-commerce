<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\UpdateProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\Tests\Unit\RedirectsErrorLog;
use Swag\AgenticCommerce\UpdateProtection\LeftoverComposerDependencyRemover;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/** @internal */
#[CoversClass(LeftoverComposerDependencyRemover::class)]
final class LeftoverComposerDependencyRemoverTest extends TestCase
{
    use RedirectsErrorLog;

    private const SHOPWARE_VERSION_WITHOUT_REMOVAL = '6.6.10.3';

    /** @var list<string> */
    private array $roots = [];

    /** @var list<string> */
    private array $removedProjectDirs = [];

    private ?\Throwable $removalFailure = null;

    protected function setUp(): void
    {
        $this->redirectErrorLog();
    }

    protected function tearDown(): void
    {
        $this->restoreErrorLog();
        (new Filesystem())->remove($this->roots);
    }

    #[Test]
    public function testItRemovesTheRequirementAtOnceOnTheCommandLine(): void
    {
        $projectDir = $this->projectRequiringThePlugin();

        $this->remover($this->container($projectDir), $this->archiveRoot(), true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        self::assertSame([$projectDir], $this->removedProjectDirs);
    }

    #[Test]
    public function testItRemovesTheRequirementOnEveryCoreBeforeTheOneThatDoesItItself(): void
    {
        foreach (['6.5.8.18', '6.6.0.0', '6.6.10.3'] as $shopwareVersion) {
            $this->removedProjectDirs = [];

            $this->remover($this->container($this->projectRequiringThePlugin()), $this->archiveRoot(), true)->removeWhenCoreDoesNot($shopwareVersion);

            self::assertCount(1, $this->removedProjectDirs, $shopwareVersion);
        }
    }

    #[Test]
    public function testItLeavesTheRequirementToCoreFrom66104On(): void
    {
        foreach (['6.6.10.4', '6.6.11.0', '6.7.0.0', '6.7.13.1'] as $shopwareVersion) {
            $this->remover($this->container($this->projectRequiringThePlugin()), $this->archiveRoot(), true)->removeWhenCoreDoesNot($shopwareVersion);
        }

        self::assertSame([], $this->removedProjectDirs);
    }

    #[Test]
    public function testItLeavesTheRequirementInAClusterSetup(): void
    {
        $container = $this->container($this->projectRequiringThePlugin(), ['shopware.deployment.cluster_setup' => true]);

        $this->remover($container, $this->archiveRoot(), true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        self::assertSame([], $this->removedProjectDirs);
    }

    #[Test]
    public function testItRemovesTheRequirementWhenTheClusterSetupIsOff(): void
    {
        $container = $this->container($this->projectRequiringThePlugin(), ['shopware.deployment.cluster_setup' => false]);

        $this->remover($container, $this->archiveRoot(), true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        self::assertCount(1, $this->removedProjectDirs);
    }

    #[Test]
    public function testItLeavesADevelopmentCheckoutWithoutTheBundledSdkAlone(): void
    {
        $pluginRoot = $this->root([]);

        $this->remover($this->container($this->projectRequiringThePlugin()), $pluginRoot, true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        self::assertSame([], $this->removedProjectDirs);
    }

    #[Test]
    public function testItLeavesAPluginInstalledInVendorAlone(): void
    {
        $projectDir = $this->projectRequiringThePlugin();
        $pluginRoot = $projectDir.'/vendor/shopware/agentic-commerce';
        mkdir($pluginRoot.'/lib/ucp-php-sdk', 0o777, true);

        $this->remover($this->container($projectDir), $pluginRoot, true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        self::assertSame([], $this->removedProjectDirs);
    }

    #[Test]
    public function testItLeavesAProjectAlone(): void
    {
        $projectDirs = [
            $this->root([]),
            $this->root(['require' => ['acme/other' => '1.0.0']]),
        ];

        foreach ($projectDirs as $projectDir) {
            $this->remover($this->container($projectDir), $this->archiveRoot(), true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);
        }

        self::assertSame([], $this->removedProjectDirs);
    }

    #[Test]
    public function testItAlsoRemovesARequirementInTheDevelopmentSection(): void
    {
        $projectDir = $this->root(['require-dev' => ['shopware/agentic-commerce' => '1.4.0']]);

        $this->remover($this->container($projectDir), $this->archiveRoot(), true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        self::assertSame([$projectDir], $this->removedProjectDirs);
    }

    #[Test]
    public function testItDoesNothingWithoutAProjectDirectory(): void
    {
        $this->remover(new Container(new ParameterBag()), $this->archiveRoot(), true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        self::assertSame([], $this->removedProjectDirs);
    }

    #[Test]
    public function testItWaitsForTheMainResponseOutsideTheCommandLineAndRunsBeforeTheOtherListeners(): void
    {
        $projectDir = $this->projectRequiringThePlugin();
        $eventDispatcher = new EventDispatcher();
        $removalsSeenByAnotherListener = [];
        $eventDispatcher->addListener(KernelEvents::RESPONSE, function () use (&$removalsSeenByAnotherListener): void {
            $removalsSeenByAnotherListener[] = \count($this->removedProjectDirs);
        }, 100);
        $container = $this->container($projectDir, [], $eventDispatcher);

        $this->remover($container, $this->archiveRoot(), false)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        self::assertSame([], $this->removedProjectDirs);

        $eventDispatcher->dispatch($this->responseEvent(HttpKernelInterface::SUB_REQUEST), KernelEvents::RESPONSE);
        $eventDispatcher->dispatch($this->responseEvent(HttpKernelInterface::MAIN_REQUEST), KernelEvents::RESPONSE);
        $eventDispatcher->dispatch($this->responseEvent(HttpKernelInterface::MAIN_REQUEST), KernelEvents::RESPONSE);

        self::assertSame([$projectDir], $this->removedProjectDirs);
        self::assertSame([0, 1, 1], $removalsSeenByAnotherListener, 'the sub request saw none, the main requests saw the removal');
    }

    #[Test]
    public function testItRegistersNoListenerWhenCoreRemovesTheRequirement(): void
    {
        $eventDispatcher = new EventDispatcher();

        $this->remover($this->container($this->projectRequiringThePlugin(), [], $eventDispatcher), $this->archiveRoot(), false)->removeWhenCoreDoesNot('6.7.0.0');

        self::assertSame([], $eventDispatcher->getListeners(KernelEvents::RESPONSE));
    }

    #[Test]
    public function testAFailedRemovalIsLoggedWithTheManualCommandAndNeverThrown(): void
    {
        $logsDir = $this->root([]);
        $this->removalFailure = new \RuntimeException('Composer exited with 2');
        $container = $this->container($this->projectRequiringThePlugin(), ['kernel.logs_dir' => $logsDir]);

        $this->remover($container, $this->archiveRoot(), true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        $log = (string) file_get_contents($logsDir.'/swag-agentic-commerce.log');
        self::assertStringContainsString('failed: Composer exited with 2', $log);
        self::assertStringContainsString('composer remove --no-scripts shopware/agentic-commerce', $log);
        self::assertStringContainsString('Composer exited with 2', $this->loggedErrors());
    }

    #[Test]
    public function testAFailedRemovalOnTheMainResponseIsLoggedAndNeverThrown(): void
    {
        $this->removalFailure = new \RuntimeException('Composer exited with 2');
        $eventDispatcher = new EventDispatcher();
        $container = $this->container($this->projectRequiringThePlugin(), [], $eventDispatcher);
        $this->remover($container, $this->archiveRoot(), false)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        $eventDispatcher->dispatch($this->responseEvent(HttpKernelInterface::MAIN_REQUEST), KernelEvents::RESPONSE);

        self::assertStringContainsString('Composer exited with 2', $this->loggedErrors());
    }

    #[Test]
    public function testTheRemovalMarksThePluginAsNotManagedByComposer(): void
    {
        $pluginId = Uuid::randomHex();
        $pluginRepository = new StaticEntityRepository([[$pluginId]]);
        $container = $this->container($this->projectRequiringThePlugin());
        $container->set('plugin.repository', $pluginRepository);

        $this->remover($container, $this->archiveRoot(), true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        self::assertSame([[['id' => $pluginId, 'managedByComposer' => false]]], $pluginRepository->updates);
    }

    #[Test]
    public function testAFailedRemovalLeavesThePluginManagedByComposer(): void
    {
        $this->removalFailure = new \RuntimeException('Composer exited with 2');
        $pluginRepository = new StaticEntityRepository([[Uuid::randomHex()]]);
        $container = $this->container($this->projectRequiringThePlugin());
        $container->set('plugin.repository', $pluginRepository);

        $this->remover($container, $this->archiveRoot(), true)->removeWhenCoreDoesNot(self::SHOPWARE_VERSION_WITHOUT_REMOVAL);

        self::assertSame([], $pluginRepository->updates);
    }

    #[Test]
    public function testTheComposerBackedRemoverDoesNothingOnACoreThatRemovesTheRequirement(): void
    {
        $projectDir = $this->projectRequiringThePlugin();

        LeftoverComposerDependencyRemover::withComposer($this->container($projectDir), $this->archiveRoot())->removeWhenCoreDoesNot('6.7.0.0');

        self::assertStringContainsString('shopware/agentic-commerce', (string) file_get_contents($projectDir.'/composer.json'));
    }

    private function remover(Container $container, string $pluginRoot, bool $isCli): LeftoverComposerDependencyRemover
    {
        return new LeftoverComposerDependencyRemover(
            $container,
            $pluginRoot,
            function (string $projectDir): void {
                $this->removedProjectDirs[] = $projectDir;

                if (null !== $this->removalFailure) {
                    throw $this->removalFailure;
                }
            },
            $isCli,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function container(string $projectDir, array $parameters = [], ?EventDispatcher $eventDispatcher = null): Container
    {
        $container = new Container(new ParameterBag(['kernel.project_dir' => $projectDir] + $parameters));
        $container->set('event_dispatcher', $eventDispatcher ?? new EventDispatcher());

        return $container;
    }

    private function responseEvent(int $requestType): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(), $requestType, new Response());
    }

    private function projectRequiringThePlugin(): string
    {
        return $this->root(['require' => ['shopware/agentic-commerce' => '1.4.0']]);
    }

    private function archiveRoot(): string
    {
        $pluginRoot = $this->root([]);
        mkdir($pluginRoot.'/lib/ucp-php-sdk', 0o777, true);

        return $pluginRoot;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function root(array $manifest): string
    {
        $root = sys_get_temp_dir().'/swag-agentic-leftover-dependency-'.bin2hex(random_bytes(6));
        mkdir($root);
        $this->roots[] = $root;
        file_put_contents($root.'/composer.json', (string) json_encode($manifest, \JSON_UNESCAPED_SLASHES));

        return $root;
    }
}
