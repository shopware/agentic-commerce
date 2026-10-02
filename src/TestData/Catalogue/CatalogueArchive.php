<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Catalogue;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\TestDataException;

/**
 * Reads the verified catalogue zip in place. Only `catalogue.json` and the images it lists are ever read, so an
 * entry with a crafted name is never touched and nothing is extracted to disk.
 *
 * @internal
 */
#[Package('framework')]
final class CatalogueArchive
{
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    private const MAX_CATALOGUE_BYTES = 5 * 1024 * 1024;
    private const IMAGE_PATH_PATTERN = '#^images/[a-z0-9-]+/[a-z0-9-]+\.webp$#';

    /**
     * @param array<string, true> $isListedByFile
     */
    private function __construct(
        private readonly \ZipArchive $zip,
        public readonly Catalogue $catalogue,
        private readonly array $isListedByFile,
    ) {
    }

    public static function open(string $path): self
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw TestDataException::unreadableCatalogueArchive($path);
        }

        $catalogue = Catalogue::fromJson(self::readEntry($zip, 'catalogue.json', self::MAX_CATALOGUE_BYTES));

        return new self($zip, $catalogue, array_fill_keys($catalogue->imageFiles(), true));
    }

    public static function validImagePath(string $file): string
    {
        if (1 !== preg_match(self::IMAGE_PATH_PATTERN, $file)) {
            throw TestDataException::invalidCatalogue($file, 'an image path images/<product>/<shot>.webp');
        }

        return $file;
    }

    public function readImage(string $file): string
    {
        if (!isset($this->isListedByFile[$file])) {
            throw TestDataException::invalidCatalogue($file, 'an image listed in catalogue.json');
        }

        return self::readEntry($this->zip, $file, self::MAX_IMAGE_BYTES);
    }

    private static function readEntry(\ZipArchive $zip, string $file, int $maxBytes): string
    {
        $stat = $zip->statName($file);
        if (false === $stat || $stat['size'] > $maxBytes) {
            throw TestDataException::invalidCatalogue($file, \sprintf('an archive entry of at most %d bytes', $maxBytes));
        }

        // The length cap also holds when the central directory understates the size.
        $content = $zip->getFromName($file, $maxBytes + 1);
        if (false === $content || \strlen($content) > $maxBytes) {
            throw TestDataException::invalidCatalogue($file, \sprintf('an archive entry of at most %d bytes', $maxBytes));
        }

        return $content;
    }
}
