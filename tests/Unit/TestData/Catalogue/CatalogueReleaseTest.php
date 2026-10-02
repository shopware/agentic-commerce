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
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueRelease;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueSource;
use Swag\AgenticCommerce\TestData\TestDataException;

/**
 * @internal
 */
#[CoversClass(CatalogueRelease::class)]
class CatalogueReleaseTest extends TestCase
{
    private const MAX_BYTES = 1000;
    private const SHA256 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var array{publicKey: string, secretKey: non-empty-string} */
    private array $keyPair;

    protected function setUp(): void
    {
        $this->keyPair = CatalogueFixture::keyPair();
    }

    public function testASignedIndexBecomesARelease(): void
    {
        [$index, $signature] = $this->sign(['size' => 500]);

        $release = CatalogueRelease::fromSignedIndex($index, $signature, [CatalogueFixture::keyPair()['publicKey'], $this->keyPair['publicKey']], self::MAX_BYTES);

        static::assertSame('1.0.0', $release->version);
        static::assertSame(CatalogueFixture::ARCHIVE, $release->archiveName);
        static::assertSame(500, $release->archiveBytes);
        static::assertSame(self::SHA256, $release->archiveSha256);
    }

    public function testThePinnedKeyVerifiesThePublishedRelease(): void
    {
        $release = CatalogueRelease::fromSignedIndex(
            (string) file_get_contents(__DIR__.'/Fixtures/v1.0.0-index.json'),
            (string) file_get_contents(__DIR__.'/Fixtures/v1.0.0-index.json.sig'),
            CatalogueSource::PUBLIC_KEYS,
            CatalogueSource::MAX_ARCHIVE_BYTES,
        );

        static::assertSame('1.0.0', $release->version);
        static::assertSame('test-product-catalogue-1.0.0.zip', $release->archiveName);
    }

    public function testAChangedIndexNoLongerVerifies(): void
    {
        [$index, $signature] = $this->sign(['size' => 500]);

        $this->expectExceptionObject(TestDataException::catalogueSignatureInvalid());

        CatalogueRelease::fromSignedIndex(str_replace('500', '600', $index), $signature, [$this->keyPair['publicKey']], self::MAX_BYTES);
    }

    public function testASignatureByAnUnpinnedKeyIsRejected(): void
    {
        [$index, $signature] = $this->sign(['size' => 500]);

        $this->expectExceptionObject(TestDataException::catalogueSignatureInvalid());

        CatalogueRelease::fromSignedIndex($index, $signature, [CatalogueFixture::keyPair()['publicKey']], self::MAX_BYTES);
    }

    public function testWithoutPinnedKeysNothingVerifies(): void
    {
        [$index, $signature] = $this->sign(['size' => 500]);

        $this->expectExceptionObject(TestDataException::catalogueSignatureInvalid());

        CatalogueRelease::fromSignedIndex($index, $signature, [], self::MAX_BYTES);
    }

    public function testAnArchiveNameWithADirectoryPartIsRejectedEvenWhenSigned(): void
    {
        [$index, $signature] = $this->sign(['size' => 500, 'archive' => '../test-product-catalogue-1.0.0.zip']);

        $this->expectExceptionObject(TestDataException::invalidCatalogue('index.archive', 'a file name test-product-catalogue-<version>.zip'));

        CatalogueRelease::fromSignedIndex($index, $signature, [$this->keyPair['publicKey']], self::MAX_BYTES);
    }

    public function testAnArchiveAboveTheSizeCapIsRejected(): void
    {
        [$index, $signature] = $this->sign(['size' => self::MAX_BYTES + 1]);

        $this->expectExceptionObject(TestDataException::invalidCatalogue('index.size', 'at most 1000 bytes'));

        CatalogueRelease::fromSignedIndex($index, $signature, [$this->keyPair['publicKey']], self::MAX_BYTES);
    }

    public function testAnUnknownIndexSchemaIsRejected(): void
    {
        [$index, $signature] = $this->sign(['size' => 500, 'schema' => 2]);

        $this->expectExceptionObject(TestDataException::unsupportedCatalogueSchema(2));

        CatalogueRelease::fromSignedIndex($index, $signature, [$this->keyPair['publicKey']], self::MAX_BYTES);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{0: string, 1: string}
     */
    private function sign(array $fields): array
    {
        $index = (string) json_encode(['schema' => 1, 'version' => '1.0.0', 'archive' => CatalogueFixture::ARCHIVE, 'sha256' => self::SHA256, 'created' => '2026-09-25T12:00:00Z', ...$fields], \JSON_THROW_ON_ERROR);

        return [$index, base64_encode(sodium_crypto_sign_detached($index, $this->keyPair['secretKey']))];
    }
}
