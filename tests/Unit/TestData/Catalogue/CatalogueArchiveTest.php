<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Catalogue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\TestData\Catalogue\Catalogue;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueArchive;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueImage;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueJsonObject;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueProduct;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueVariantOption;
use Swag\AgenticCommerce\TestData\TestDataException;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * @internal
 */
#[CoversClass(CatalogueArchive::class)]
#[CoversClass(Catalogue::class)]
#[CoversClass(CatalogueJsonObject::class)]
#[CoversClass(CatalogueProduct::class)]
#[CoversClass(CatalogueImage::class)]
#[CoversClass(CatalogueVariantOption::class)]
class CatalogueArchiveTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/swag-ac-catalogue-archive-'.bin2hex(random_bytes(4)).'.zip';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testItParsesTheCatalogueAndReadsListedImagesInPlace(): void
    {
        $archive = CatalogueArchive::open(CatalogueFixture::writeZip($this->path, CatalogueFixture::catalogue()));

        $catalogue = $archive->catalogue;
        static::assertSame('1.0.0', $catalogue->version);
        static::assertCount(8, $catalogue->products);
        static::assertEquals(new TranslatedText('Glass', 'Glas'), $catalogue->propertyGroupByKey['material']['options']['glass']);
        static::assertSame('Pocket Meadow Toys', $catalogue->manufacturerByKey['pocket-meadow']['name']);

        $bunny = $catalogue->products[0];
        static::assertSame('knitted-bunny', $bunny->id);
        static::assertSame('colour', $bunny->variantPropertyGroup);
        static::assertSame(['cream', 'pink', 'pattern-pdf'], array_map(static fn (CatalogueVariantOption $option): string => $option->key, $bunny->variantOptions));
        static::assertSame('images/knitted-bunny/variant-pink.webp', $bunny->variantOptions[1]->coverFile);
        static::assertTrue($bunny->hasDigitalOption());
        static::assertEquals(['care_note' => new TranslatedText('Hand wash.', 'Handwäsche.'), 'warranty_years' => 1], $bunny->customFieldByName);

        $honey = $catalogue->products[4];
        static::assertSame(CatalogueProduct::TAX_REDUCED, $honey->taxClass);
        static::assertSame(['code' => 'kg', 'purchase' => 0.5, 'reference' => 1.0], $honey->unit);
        static::assertSame(['material' => ['glass']], $honey->optionKeysByGroup);

        static::assertSame(CatalogueFixture::imageBytes('images/knitted-bunny/cover.webp'), $archive->readImage('images/knitted-bunny/cover.webp'));
    }

    public function testAnEntryTheCatalogueDoesNotListIsNeverRead(): void
    {
        $archive = CatalogueArchive::open(CatalogueFixture::writeZip($this->path, CatalogueFixture::catalogue(), ['../../escape.webp' => 'x', 'images/extra/cover.webp' => 'x']));

        $this->expectExceptionObject(TestDataException::invalidCatalogue('images/extra/cover.webp', 'an image listed in catalogue.json'));

        $archive->readImage('images/extra/cover.webp');
    }

    public function testACatalogueListingAPathOutsideTheImagesTreeIsRejected(): void
    {
        $catalogue = CatalogueFixture::catalogue();
        $catalogue['products'][0]['images'][0]['file'] = 'images/../../etc/passwd.webp';

        $this->expectExceptionObject(TestDataException::invalidCatalogue('images/../../etc/passwd.webp', 'an image path images/<product>/<shot>.webp'));

        CatalogueArchive::open(CatalogueFixture::writeZip($this->path, $catalogue));
    }

    public function testAnImageAboveTheSizeCapIsRejected(): void
    {
        CatalogueFixture::writeZip($this->path, CatalogueFixture::catalogue());
        $zip = new \ZipArchive();
        $zip->open($this->path);
        $zip->addFromString('images/knitted-bunny/cover.webp', str_repeat("\0", CatalogueArchive::MAX_IMAGE_BYTES + 1));
        $zip->close();

        $this->expectExceptionObject(TestDataException::invalidCatalogue('images/knitted-bunny/cover.webp', \sprintf('an archive entry of at most %d bytes', CatalogueArchive::MAX_IMAGE_BYTES)));

        CatalogueArchive::open($this->path)->readImage('images/knitted-bunny/cover.webp');
    }

    public function testAnUnknownCatalogueSchemaIsRejected(): void
    {
        $this->expectExceptionObject(TestDataException::unsupportedCatalogueSchema(2));

        CatalogueArchive::open(CatalogueFixture::writeZip($this->path, ['schema' => 2] + CatalogueFixture::catalogue()));
    }

    public function testAFileThatIsNoZipIsRejected(): void
    {
        file_put_contents($this->path, 'not a zip');

        $this->expectExceptionObject(TestDataException::unreadableCatalogueArchive($this->path));

        CatalogueArchive::open($this->path);
    }
}
