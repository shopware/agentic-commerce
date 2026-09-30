<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData;

use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
#[Package('framework')]
final class TestDataException extends HttpException
{
    public const MISSING_TAX = 'SWAG_AGENTIC_COMMERCE__TEST_DATA_MISSING_TAX';
    public const MISSING_PRODUCT_MEDIA_FOLDER = 'SWAG_AGENTIC_COMMERCE__TEST_DATA_MISSING_PRODUCT_MEDIA_FOLDER';
    public const CATALOGUE_UNAVAILABLE = 'SWAG_AGENTIC_COMMERCE__TEST_DATA_CATALOGUE_UNAVAILABLE';
    public const CATALOGUE_SIGNATURE_INVALID = 'SWAG_AGENTIC_COMMERCE__TEST_DATA_CATALOGUE_SIGNATURE_INVALID';
    public const CATALOGUE_ARCHIVE_MISMATCH = 'SWAG_AGENTIC_COMMERCE__TEST_DATA_CATALOGUE_ARCHIVE_MISMATCH';
    public const CATALOGUE_SCHEMA_UNSUPPORTED = 'SWAG_AGENTIC_COMMERCE__TEST_DATA_CATALOGUE_SCHEMA_UNSUPPORTED';
    public const CATALOGUE_INVALID = 'SWAG_AGENTIC_COMMERCE__TEST_DATA_CATALOGUE_INVALID';
    public const CATALOGUE_ARCHIVE_UNREADABLE = 'SWAG_AGENTIC_COMMERCE__TEST_DATA_CATALOGUE_ARCHIVE_UNREADABLE';

    public static function missingTax(): self
    {
        return new self(Response::HTTP_INTERNAL_SERVER_ERROR, self::MISSING_TAX, 'The shop has no tax rate to assign to the test products.');
    }

    public static function missingProductMediaFolder(): self
    {
        return new self(Response::HTTP_INTERNAL_SERVER_ERROR, self::MISSING_PRODUCT_MEDIA_FOLDER, 'The shop has no default media folder for products to put the test images in.');
    }

    public static function catalogueUnavailable(string $reason): self
    {
        return new self(Response::HTTP_SERVICE_UNAVAILABLE, self::CATALOGUE_UNAVAILABLE, \sprintf('The product catalogue could not be fetched: %s', $reason), ['reason' => $reason]);
    }

    public static function catalogueSignatureInvalid(): self
    {
        return new self(Response::HTTP_BAD_GATEWAY, self::CATALOGUE_SIGNATURE_INVALID, 'The product catalogue index is not signed by a key this plugin trusts.');
    }

    public static function catalogueArchiveMismatch(string $archive, string $reason): self
    {
        return new self(Response::HTTP_BAD_GATEWAY, self::CATALOGUE_ARCHIVE_MISMATCH, \sprintf('The product catalogue archive %s %s.', $archive, $reason), ['archive' => $archive]);
    }

    public static function unsupportedCatalogueSchema(int $schema): self
    {
        return new self(Response::HTTP_BAD_GATEWAY, self::CATALOGUE_SCHEMA_UNSUPPORTED, \sprintf('The product catalogue uses schema %d, which this plugin version does not read. Update the plugin or run with --offline.', $schema), ['schema' => $schema]);
    }

    public static function invalidCatalogue(string $path, string $expected): self
    {
        return new self(Response::HTTP_BAD_GATEWAY, self::CATALOGUE_INVALID, \sprintf('The product catalogue is invalid: %s must be %s.', $path, $expected), ['path' => $path]);
    }

    public static function unreadableCatalogueArchive(string $archivePath): self
    {
        return new self(Response::HTTP_BAD_GATEWAY, self::CATALOGUE_ARCHIVE_UNREADABLE, \sprintf('The product catalogue archive %s cannot be opened as a zip file.', $archivePath), ['archivePath' => $archivePath]);
    }
}
