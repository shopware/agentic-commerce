<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\UpdateProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Tests\Unit\RedirectsErrorLog;
use Swag\AgenticCommerce\UpdateProtection\PendingSdkSchema;
use Symfony\Component\Filesystem\Filesystem;

/** @internal */
#[CoversClass(PendingSdkSchema::class)]
final class PendingSdkSchemaTest extends TestCase
{
    use RedirectsErrorLog;

    private const MARKER_PATH = '/var/swag-agentic-commerce-sdk-schema-pending';

    /** @var list<string> */
    private array $projectDirs = [];

    protected function setUp(): void
    {
        $this->redirectErrorLog();
    }

    protected function tearDown(): void
    {
        $this->restoreErrorLog();
        (new Filesystem())->remove($this->projectDirs);
    }

    #[Test]
    public function testMarkingCreatesTheMarkerAndTheVarDirectoryBelowIt(): void
    {
        $projectDir = $this->projectDir();

        PendingSdkSchema::markIn($projectDir);

        self::assertFileExists($projectDir.self::MARKER_PATH);
    }

    #[Test]
    public function testMarkingAnAlreadyMarkedProjectKeepsTheMarker(): void
    {
        $projectDir = $this->projectDir();

        PendingSdkSchema::markIn($projectDir);
        PendingSdkSchema::markIn($projectDir);

        self::assertFileExists($projectDir.self::MARKER_PATH);
        self::assertSame('', $this->loggedErrors());
    }

    #[Test]
    public function testAProjectWithoutMarkerNeverRunsTheSchema(): void
    {
        $schemaRuns = 0;

        PendingSdkSchema::applyWhenMarkedIn($this->projectDir(), static function () use (&$schemaRuns): bool {
            ++$schemaRuns;

            return true;
        });

        self::assertSame(0, $schemaRuns);
    }

    #[Test]
    public function testAMarkedProjectRunsTheSchemaOnceAndRemovesTheMarker(): void
    {
        $projectDir = $this->projectDir();
        PendingSdkSchema::markIn($projectDir);
        $schemaRuns = 0;
        $applySchema = static function () use (&$schemaRuns): bool {
            ++$schemaRuns;

            return true;
        };

        PendingSdkSchema::applyWhenMarkedIn($projectDir, $applySchema);
        PendingSdkSchema::applyWhenMarkedIn($projectDir, $applySchema);

        self::assertSame(1, $schemaRuns);
        self::assertFileDoesNotExist($projectDir.self::MARKER_PATH);
    }

    #[Test]
    public function testAThrowingSchemaKeepsTheMarkerAndLogsWhy(): void
    {
        $projectDir = $this->projectDir();
        PendingSdkSchema::markIn($projectDir);

        PendingSdkSchema::applyWhenMarkedIn($projectDir, static function (): bool {
            throw new \RuntimeException('Table is locked');
        });

        self::assertFileExists($projectDir.self::MARKER_PATH);
        self::assertStringContainsString('Table is locked', $this->loggedErrors());
    }

    #[Test]
    public function testASchemaThatCouldNotBeAppliedKeepsTheMarkerWithoutLogging(): void
    {
        $projectDir = $this->projectDir();
        PendingSdkSchema::markIn($projectDir);

        PendingSdkSchema::applyWhenMarkedIn($projectDir, static fn (): bool => false);

        self::assertFileExists($projectDir.self::MARKER_PATH);
        self::assertSame('', $this->loggedErrors());
    }

    #[Test]
    public function testAnUnwritableProjectDirectoryLogsInsteadOfThrowing(): void
    {
        $projectDirThatIsAFile = $this->projectDir().'/not-a-directory';
        file_put_contents($projectDirThatIsAFile, '');

        PendingSdkSchema::markIn($projectDirThatIsAFile);

        self::assertStringContainsString('could not be written', $this->loggedErrors());
    }

    private function projectDir(): string
    {
        $projectDir = sys_get_temp_dir().'/swag-agentic-pending-schema-'.bin2hex(random_bytes(6));
        mkdir($projectDir);
        $this->projectDirs[] = $projectDir;

        return $projectDir;
    }
}
