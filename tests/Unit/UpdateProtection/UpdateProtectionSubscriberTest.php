<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\UpdateProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\UpdateProtection\ClassPreloader;
use Swag\AgenticCommerce\UpdateProtection\UpdateProtectionSubscriber;
use Swag\AgenticCommerce\UpdateProtection\UpdateWindow;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/** @internal */
#[CoversClass(UpdateProtectionSubscriber::class)]
final class UpdateProtectionSubscriberTest extends TestCase
{
    private TemporaryPluginRoot $root;

    private ClassLoadRecorder $recorder;

    private ArrayAdapter $restartSignalPool;

    private UpdateProtectionSubscriber $subscriber;

    protected function setUp(): void
    {
        UpdateWindow::close();

        $this->root = TemporaryPluginRoot::create();
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass')], [ClassLoadRecorder::className('SdkClass')]);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath());
        $this->restartSignalPool = new ArrayAdapter();
        $this->subscriber = new UpdateProtectionSubscriber(
            new ClassPreloader($this->root->path(), $this->root->markerPath()),
            $this->root->path(),
            fn (): ArrayAdapter => $this->restartSignalPool,
        );
    }

    protected function tearDown(): void
    {
        UpdateWindow::close();
        $this->recorder->stop();
        $this->root->remove();
    }

    #[Test]
    public function testItSubscribesToTheControllerResponseAndConsoleEvents(): void
    {
        $subscribedEvents = UpdateProtectionSubscriber::getSubscribedEvents();

        static::assertArrayHasKey(KernelEvents::CONTROLLER, $subscribedEvents);
        static::assertArrayHasKey(KernelEvents::RESPONSE, $subscribedEvents);
        static::assertArrayHasKey(ConsoleEvents::COMMAND, $subscribedEvents);
        static::assertArrayHasKey(ConsoleEvents::TERMINATE, $subscribedEvents);
    }

    #[Test]
    public function testTheDownloadOfThisPluginPreloadsOpensTheWindowAndSignalsTheWorkersAtTheResponse(): void
    {
        $download = $this->controllerEvent('api.extension.download', ['technicalName' => 'SwagAgenticCommerce']);

        $this->subscriber->onController($download);

        static::assertSame([ClassLoadRecorder::className('PluginClass')], $this->recorder->requestedClassNames());
        static::assertTrue(UpdateWindow::isOpen());
        static::assertFalse($this->hasRestartSignal());

        $this->subscriber->onResponse($this->responseEvent($download->getRequest(), HttpKernelInterface::MAIN_REQUEST));

        static::assertTrue($this->hasRestartSignal());
    }

    #[Test]
    public function testTheDownloadOfAnotherExtensionChangesNothing(): void
    {
        $this->subscriber->onController($this->controllerEvent('api.extension.download', ['technicalName' => 'SwagOtherPlugin']));

        $this->assertNothingProtected();
    }

    #[Test]
    public function testAnUploadWhoseArchiveStartsWithThisPluginPreloadsAndOpensTheWindow(): void
    {
        $archive = $this->root->writeArchive('own.zip', ['SwagAgenticCommerce/', 'SwagAgenticCommerce/composer.json']);

        $this->subscriber->onController($this->controllerEvent('api.extension.upload', [], $archive));

        static::assertSame([ClassLoadRecorder::className('PluginClass')], $this->recorder->requestedClassNames());
        static::assertTrue(UpdateWindow::isOpen());
    }

    #[Test]
    public function testAnUploadOfAnotherPluginChangesNothing(): void
    {
        $archive = $this->root->writeArchive('other.zip', ['SwagOtherPlugin/', 'SwagOtherPlugin/composer.json']);

        $this->subscriber->onController($this->controllerEvent('api.extension.upload', [], $archive));

        $this->assertNothingProtected();
    }

    #[Test]
    public function testAnUploadWithoutAFileChangesNothing(): void
    {
        $this->subscriber->onController($this->controllerEvent('api.extension.upload'));

        $this->assertNothingProtected();
    }

    #[Test]
    public function testAnotherRouteChangesNothing(): void
    {
        $this->subscriber->onController($this->controllerEvent('api.extension.update', ['technicalName' => 'SwagAgenticCommerce']));

        $this->assertNothingProtected();
    }

    #[Test]
    public function testASubRequestChangesNothing(): void
    {
        $this->subscriber->onController($this->controllerEvent('api.extension.download', ['technicalName' => 'SwagAgenticCommerce'], null, HttpKernelInterface::SUB_REQUEST));

        $this->assertNothingProtected();
    }

    #[Test]
    public function testTheResponseOfASubRequestDoesNotSignalTheWorkers(): void
    {
        $download = $this->controllerEvent('api.extension.download', ['technicalName' => 'SwagAgenticCommerce']);
        $this->subscriber->onController($download);

        $this->subscriber->onResponse($this->responseEvent($download->getRequest(), HttpKernelInterface::SUB_REQUEST));

        static::assertFalse($this->hasRestartSignal());
    }

    #[Test]
    public function testTheSdkClassesAreLeftAloneWhileThePluginRootDoesNotBundleThem(): void
    {
        $this->subscriber->onController($this->controllerEvent('api.extension.download', ['technicalName' => 'SwagAgenticCommerce']));

        static::assertNotContains(ClassLoadRecorder::className('SdkClass'), $this->recorder->requestedClassNames());
    }

    #[Test]
    public function testZipImportOfThisPluginPreloadsOpensTheWindowAndSignalsTheWorkersWhenTheCommandEnds(): void
    {
        $archive = $this->root->writeArchive('own.zip', ['SwagAgenticCommerce/composer.json']);
        $command = $this->commandEvent('plugin:zip-import', new ArrayInput(['zip-file' => $archive], new InputDefinition([new InputArgument('zip-file')])));

        $this->subscriber->onCommand($command);

        static::assertSame([ClassLoadRecorder::className('PluginClass')], $this->recorder->requestedClassNames());
        static::assertTrue(UpdateWindow::isOpen());

        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command->getCommand(), $command->getInput(), new NullOutput(), 0));

        static::assertTrue($this->hasRestartSignal());
    }

    #[Test]
    public function testZipImportOfAnotherPluginChangesNothing(): void
    {
        $archive = $this->root->writeArchive('other.zip', ['SwagOtherPlugin/composer.json']);

        $this->subscriber->onCommand($this->commandEvent('plugin:zip-import', new ArrayInput(['zip-file' => $archive], new InputDefinition([new InputArgument('zip-file')]))));

        $this->assertNothingProtected();
    }

    #[Test]
    public function testZipImportWithoutAnArchivePathChangesNothing(): void
    {
        $this->subscriber->onCommand($this->commandEvent('plugin:zip-import', new ArrayInput([], new InputDefinition([new InputArgument('zip-file')]))));
        $this->subscriber->onCommand($this->commandEvent('plugin:zip-import', new ArrayInput([])));

        $this->assertNothingProtected();
    }

    #[Test]
    public function testStoreDownloadOfThisPluginPreloadsButKeepsTheWindowClosedBecauseTheUpdateRunsAfterwards(): void
    {
        $command = $this->commandEvent('store:download', $this->storeDownloadInput('SwagAgenticCommerce'));

        $this->subscriber->onCommand($command);

        static::assertSame([ClassLoadRecorder::className('PluginClass')], $this->recorder->requestedClassNames());
        static::assertFalse(UpdateWindow::isOpen());

        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command->getCommand(), $command->getInput(), new NullOutput(), 0));

        static::assertTrue($this->hasRestartSignal());
    }

    #[Test]
    public function testStoreDownloadOfAnotherPluginChangesNothing(): void
    {
        $this->subscriber->onCommand($this->commandEvent('store:download', $this->storeDownloadInput('SwagOtherPlugin')));

        $this->assertNothingProtected();
    }

    #[Test]
    public function testAnotherCommandChangesNothing(): void
    {
        $this->subscriber->onCommand($this->commandEvent('plugin:update', $this->storeDownloadInput('SwagAgenticCommerce')));

        $this->assertNothingProtected();
    }

    #[Test]
    public function testTheWorkersAreSignalledOnlyOnce(): void
    {
        $download = $this->controllerEvent('api.extension.download', ['technicalName' => 'SwagAgenticCommerce']);
        $this->subscriber->onController($download);
        $response = $this->responseEvent($download->getRequest(), HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->onResponse($response);
        $this->restartSignalPool->deleteItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);

        $this->subscriber->onResponse($response);

        static::assertFalse($this->hasRestartSignal());
    }

    #[Test]
    public function testItProtectsWithoutAWorkerSignalWhenTheShopHasNoRestartSignalPool(): void
    {
        $subscriber = new UpdateProtectionSubscriber(
            new ClassPreloader($this->root->path(), $this->root->markerPath()),
            $this->root->path(),
        );
        $download = $this->controllerEvent('api.extension.download', ['technicalName' => 'SwagAgenticCommerce']);

        $subscriber->onController($download);
        $subscriber->onResponse($this->responseEvent($download->getRequest(), HttpKernelInterface::MAIN_REQUEST));

        static::assertFalse(UpdateWindow::isOpen());
        static::assertFalse($this->hasRestartSignal());
    }

    #[Test]
    public function testTheWindowStaysOpenUntilTheResponseOfTheMainRequest(): void
    {
        $download = $this->controllerEvent('api.extension.download', ['technicalName' => 'SwagAgenticCommerce']);
        $this->subscriber->onController($download);

        $this->subscriber->onResponse($this->responseEvent($download->getRequest(), HttpKernelInterface::SUB_REQUEST));

        static::assertTrue(UpdateWindow::isOpen());

        $this->subscriber->onResponse($this->responseEvent($download->getRequest(), HttpKernelInterface::MAIN_REQUEST));

        static::assertFalse(UpdateWindow::isOpen());
    }

    #[Test]
    public function testTheWindowOfACommandIsClosedWhenTheCommandEnds(): void
    {
        $archive = $this->root->writeArchive('own.zip', ['SwagAgenticCommerce/composer.json']);
        $command = $this->commandEvent('plugin:zip-import', new ArrayInput(['zip-file' => $archive], new InputDefinition([new InputArgument('zip-file')])));
        $this->subscriber->onCommand($command);

        $this->subscriber->onTerminate(new ConsoleTerminateEvent($command->getCommand(), $command->getInput(), new NullOutput(), 0));

        static::assertFalse(UpdateWindow::isOpen());
    }

    #[Test]
    public function testAWindowThatThisSubscriberDidNotOpenIsLeftOpen(): void
    {
        UpdateWindow::open();

        $this->subscriber->onResponse($this->responseEvent(new Request(), HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent(new Command('any'), new ArrayInput([]), new NullOutput(), 0));

        static::assertTrue(UpdateWindow::isOpen());
    }

    private function assertNothingProtected(): void
    {
        static::assertSame([], $this->recorder->requestedClassNames());
        static::assertFalse(UpdateWindow::isOpen());

        $this->subscriber->onResponse($this->responseEvent(new Request(), HttpKernelInterface::MAIN_REQUEST));
        $this->subscriber->onTerminate(new ConsoleTerminateEvent(new Command('any'), new ArrayInput([]), new NullOutput(), 0));

        static::assertFalse($this->hasRestartSignal());
    }

    private function hasRestartSignal(): bool
    {
        return $this->restartSignalPool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY)->isHit();
    }

    /**
     * @param array<string, string> $routeParameters
     */
    private function controllerEvent(string $route, array $routeParameters = [], ?string $uploadedArchivePath = null, int $requestType = HttpKernelInterface::MAIN_REQUEST): ControllerEvent
    {
        $files = null === $uploadedArchivePath ? [] : ['file' => new UploadedFile($uploadedArchivePath, 'plugin.zip', 'application/zip', null, true)];

        return new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn () => new Response(),
            new Request([], [], ['_route' => $route, ...$routeParameters], [], $files),
            $requestType,
        );
    }

    private function responseEvent(Request $request, int $requestType): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $requestType, new Response());
    }

    private function commandEvent(string $commandName, ArrayInput $input): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($commandName), $input, new NullOutput());
    }

    private function storeDownloadInput(string $pluginName): ArrayInput
    {
        return new ArrayInput(
            ['--pluginName' => $pluginName],
            new InputDefinition([new InputOption('pluginName', 'p', InputOption::VALUE_REQUIRED)]),
        );
    }
}
