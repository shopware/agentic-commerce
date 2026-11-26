<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\UpdateProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\BundledClassLoading;
use Swag\AgenticCommerce\Tests\Unit\CollectingLogger;
use Swag\AgenticCommerce\UpdateProtection\ClassPreloader;

/** @internal */
#[CoversClass(ClassPreloader::class)]
final class ClassPreloaderTest extends TestCase
{
    private TemporaryPluginRoot $root;

    private ?ClassLoadRecorder $recorder = null;

    protected function setUp(): void
    {
        $this->root = TemporaryPluginRoot::create();
    }

    protected function tearDown(): void
    {
        $this->recorder?->stop();
        $this->root->remove();
    }

    #[Test]
    public function testItLoadsThePluginClassesAndLeavesTheSdkClassesAloneWhenTheSdkIsNotBundled(): void
    {
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass')], [ClassLoadRecorder::className('SdkClass')]);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath());

        $this->createPreloader()->preload(false);

        static::assertSame([ClassLoadRecorder::className('PluginClass')], $this->recorder->requestedClassNames());
    }

    #[Test]
    public function testItLoadsTheSdkClassesToo(): void
    {
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass')], [ClassLoadRecorder::className('SdkClass')]);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath());

        $this->createPreloader()->preload(true);

        static::assertSame(
            [ClassLoadRecorder::className('PluginClass'), ClassLoadRecorder::className('SdkClass')],
            $this->recorder->requestedClassNames(),
        );
    }

    #[Test]
    public function testItGoesOnAfterAClassThatCannotBeLoadedAndReportsIt(): void
    {
        $classWithoutParent = ClassLoadRecorder::className('ClassWithoutParent');
        $this->root->writeClassLists([$classWithoutParent, ClassLoadRecorder::className('ClassAfterTheFailure')], []);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath(), [$classWithoutParent]);

        $errorMessageByClassName = $this->createPreloader()->preload(false);

        static::assertSame([$classWithoutParent => \sprintf('Class "%s" not found', $classWithoutParent)], $errorMessageByClassName);
        static::assertContains(ClassLoadRecorder::className('ClassAfterTheFailure'), $this->recorder->requestedClassNames());
    }

    #[Test]
    public function testTheMarkerExistsWhileClassesLoadAndIsGoneAfterwards(): void
    {
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass')], []);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath());

        $this->createPreloader()->preload(false);

        static::assertTrue($this->recorder->wasMarkerPresentOnEveryRequest());
        static::assertFileDoesNotExist($this->root->markerPath());
    }

    #[Test]
    public function testAMarkerFromAPreloadThatDiedSkipsThePreloadOnceAndIsRemoved(): void
    {
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass')], []);
        $this->root->writeMarker();
        $this->recorder = new ClassLoadRecorder($this->root->markerPath());
        $logger = new CollectingLogger();
        $preloader = $this->createPreloader($logger);

        $skippedErrors = $preloader->preload(false);

        static::assertSame([], $skippedErrors);
        static::assertSame([], $this->recorder->requestedClassNames());
        static::assertFileDoesNotExist($this->root->markerPath());
        static::assertSame('warning', $logger->records[0]['level']);

        $preloader->preload(false);

        static::assertSame([ClassLoadRecorder::className('PluginClass')], $this->recorder->requestedClassNames());
    }

    #[Test]
    public function testItLogsHowManyClassesItLoadedAndHowLongItTook(): void
    {
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass'), ClassLoadRecorder::className('ClassWithoutParent')], []);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath(), [ClassLoadRecorder::className('ClassWithoutParent')]);
        $logger = new CollectingLogger();

        $this->createPreloader($logger)->preload(false);

        static::assertCount(1, $logger->records);
        static::assertSame('debug', $logger->records[0]['level']);
        static::assertSame(2, $logger->records[0]['context']['classes']);
        static::assertSame(1, $logger->records[0]['context']['failed']);
        static::assertIsInt($logger->records[0]['context']['milliseconds']);
    }

    #[Test]
    public function testADevelopmentCheckoutWithoutAClassListLoadsNothingAndLeavesNoMarker(): void
    {
        $this->recorder = new ClassLoadRecorder($this->root->markerPath());

        $errorMessageByClassName = $this->createPreloader()->preload(true);

        static::assertSame([], $errorMessageByClassName);
        static::assertSame([], $this->recorder->requestedClassNames());
        static::assertFileDoesNotExist($this->root->markerPath());
    }

    #[Test]
    public function testAClassListOfTheWrongShapeLoadsNothing(): void
    {
        file_put_contents($this->root->path().BundledClassLoading::CLASS_LIST_FILE, '<?php return ["plugin" => "not a list"];');
        $this->recorder = new ClassLoadRecorder($this->root->markerPath());

        static::assertSame([], $this->createPreloader()->preload(true));
        static::assertFileDoesNotExist($this->root->markerPath());
    }

    #[Test]
    public function testItRecordsThatEveryClassOfTheListLoaded(): void
    {
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass')], [ClassLoadRecorder::className('SdkClass')]);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath());

        $this->createPreloader()->preload(true);

        static::assertTrue(ClassPreloader::hasLoadedEveryClass());
    }

    #[Test]
    public function testAClassThatFailedToLoadIsNotRecordedAsLoadedEveryClass(): void
    {
        $incompatibleClass = ClassLoadRecorder::className('IncompatibleClass');
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass'), $incompatibleClass], []);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath(), [], [$incompatibleClass]);

        $this->createPreloader()->preload(false);

        static::assertFalse(ClassPreloader::hasLoadedEveryClass());
    }

    #[Test]
    public function testAClassWhoseParentThisCoreLineLacksStillCountsAsLoadedEveryClass(): void
    {
        $classWithoutParent = ClassLoadRecorder::className('ClassWithoutParent');
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass'), $classWithoutParent], []);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath(), [$classWithoutParent]);

        $this->createPreloader()->preload(false);

        static::assertTrue(ClassPreloader::hasLoadedEveryClass());
    }

    #[Test]
    public function testAFailureInTheSdkListCountsOnlyWhenTheSdkClassesWereAskedFor(): void
    {
        $incompatibleSdkClass = ClassLoadRecorder::className('IncompatibleSdkClass');
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass')], [$incompatibleSdkClass]);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath(), [], [$incompatibleSdkClass]);
        $preloader = $this->createPreloader();

        $preloader->preload(false);
        static::assertTrue(ClassPreloader::hasLoadedEveryClass());

        $preloader->preload(true);
        static::assertFalse(ClassPreloader::hasLoadedEveryClass());
    }

    #[Test]
    public function testTheRecordIsOfTheLastPreloadAndASkippedPreloadDoesNotCount(): void
    {
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass')], []);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath());
        $preloader = $this->createPreloader();
        $preloader->preload(false);
        static::assertTrue(ClassPreloader::hasLoadedEveryClass());
        $this->root->writeMarker();

        $preloader->preload(false);

        static::assertFalse(ClassPreloader::hasLoadedEveryClass());
    }

    #[Test]
    public function testADevelopmentCheckoutWithoutAClassListDoesNotCountAsLoadedEveryClass(): void
    {
        $this->root->writeClassLists([ClassLoadRecorder::className('PluginClass')], []);
        $this->recorder = new ClassLoadRecorder($this->root->markerPath());
        $this->createPreloader()->preload(false);
        unlink($this->root->path().BundledClassLoading::CLASS_LIST_FILE);

        $this->createPreloader()->preload(false);

        static::assertFalse(ClassPreloader::hasLoadedEveryClass());
    }

    private function createPreloader(?CollectingLogger $logger = null): ClassPreloader
    {
        return new ClassPreloader($this->root->path(), $this->root->markerPath(), $logger);
    }
}
