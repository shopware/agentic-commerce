<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\UpdateProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\UpdateProtection\ArchiveFirstEntry;

/** @internal */
#[CoversClass(ArchiveFirstEntry::class)]
final class ArchiveFirstEntryTest extends TestCase
{
    private TemporaryPluginRoot $root;

    protected function setUp(): void
    {
        $this->root = TemporaryPluginRoot::create();
    }

    protected function tearDown(): void
    {
        $this->root->remove();
    }

    #[Test]
    public function testAnArchiveWhoseFirstEntryIsTheDirectoryMatches(): void
    {
        $archive = $this->root->writeArchive('directory-first.zip', ['SwagAgenticCommerce/', 'SwagAgenticCommerce/composer.json']);

        static::assertTrue(ArchiveFirstEntry::isInDirectory($archive, 'SwagAgenticCommerce'));
    }

    #[Test]
    public function testAnArchiveWhoseFirstEntryIsAFileInTheDirectoryMatches(): void
    {
        $archive = $this->root->writeArchive('file-first.zip', ['SwagAgenticCommerce/composer.json']);

        static::assertTrue(ArchiveFirstEntry::isInDirectory($archive, 'SwagAgenticCommerce'));
    }

    #[Test]
    public function testAnArchiveOfAnotherPluginDoesNotMatch(): void
    {
        $archive = $this->root->writeArchive('other-plugin.zip', ['SwagOtherPlugin/', 'SwagAgenticCommerce/composer.json']);

        static::assertFalse(ArchiveFirstEntry::isInDirectory($archive, 'SwagAgenticCommerce'));
    }

    #[Test]
    public function testADirectoryWithTheSamePrefixDoesNotMatch(): void
    {
        $archive = $this->root->writeArchive('same-prefix.zip', ['SwagAgenticCommerceExtra/composer.json']);

        static::assertFalse(ArchiveFirstEntry::isInDirectory($archive, 'SwagAgenticCommerce'));
    }

    #[Test]
    public function testAnEmptyArchiveDoesNotMatch(): void
    {
        $archivePath = $this->root->path().'/empty.zip';
        file_put_contents($archivePath, "PK\x05\x06".str_repeat("\0", 18));

        static::assertFalse(ArchiveFirstEntry::isInDirectory($archivePath, 'SwagAgenticCommerce'));
    }

    #[Test]
    public function testAFileThatIsNoArchiveDoesNotMatch(): void
    {
        $notAnArchive = $this->root->path().'/plugin.txt';
        file_put_contents($notAnArchive, 'not a zip file');

        static::assertFalse(ArchiveFirstEntry::isInDirectory($notAnArchive, 'SwagAgenticCommerce'));
    }

    #[Test]
    public function testAMissingFileDoesNotMatch(): void
    {
        static::assertFalse(ArchiveFirstEntry::isInDirectory($this->root->path().'/missing.zip', 'SwagAgenticCommerce'));
    }
}
