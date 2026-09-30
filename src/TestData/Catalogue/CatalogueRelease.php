<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Catalogue;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\TestDataException;

/**
 * @internal
 */
#[Package('framework')]
final class CatalogueRelease
{
    public const INDEX_SCHEMA_VERSION = 1;
    private const ARCHIVE_NAME_PATTERN = '#^test-product-catalogue-\d+\.\d+\.\d+\.zip$#';

    private function __construct(
        public readonly string $version,
        public readonly string $archiveName,
        public readonly int $archiveBytes,
        public readonly string $archiveSha256,
        public readonly string $indexJson,
        public readonly string $signature,
    ) {
    }

    /**
     * @param string       $signature  base64, as published in `index.json.sig`
     * @param list<string> $publicKeys base64 raw Ed25519 keys; any one of them may have signed
     */
    public static function fromSignedIndex(string $indexJson, string $signature, array $publicKeys, int $maxArchiveBytes): self
    {
        $rawSignature = base64_decode(trim($signature), true);
        if (false === $rawSignature || \SODIUM_CRYPTO_SIGN_BYTES !== \strlen($rawSignature) || !self::isSignedByAnyOf($indexJson, $rawSignature, $publicKeys)) {
            throw TestDataException::catalogueSignatureInvalid();
        }

        try {
            $indexFields = json_decode($indexJson, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw TestDataException::invalidCatalogue('index.json', 'valid JSON: '.$exception->getMessage());
        }
        if (!\is_array($indexFields)) {
            throw TestDataException::invalidCatalogue('index.json', 'an object');
        }

        $index = new CatalogueJsonObject($indexFields, 'index');
        if (self::INDEX_SCHEMA_VERSION !== $index->requireInteger('schema')) {
            throw TestDataException::unsupportedCatalogueSchema($index->requireInteger('schema'));
        }

        // The name becomes a cache file path, so it must never carry a directory part.
        $archiveName = $index->requireString('archive');
        if (1 !== preg_match(self::ARCHIVE_NAME_PATTERN, $archiveName)) {
            throw TestDataException::invalidCatalogue('index.archive', 'a file name test-product-catalogue-<version>.zip');
        }

        $archiveBytes = $index->requireInteger('size');
        if ($archiveBytes <= 0 || $archiveBytes > $maxArchiveBytes) {
            throw TestDataException::invalidCatalogue('index.size', \sprintf('at most %d bytes', $maxArchiveBytes));
        }

        $archiveSha256 = $index->requireString('sha256');
        if (1 !== preg_match('#^[0-9a-f]{64}$#', $archiveSha256)) {
            throw TestDataException::invalidCatalogue('index.sha256', 'a SHA-256 hex digest');
        }

        return new self($index->requireString('version'), $archiveName, $archiveBytes, $archiveSha256, $indexJson, trim($signature));
    }

    /**
     * @param list<string> $publicKeys
     */
    private static function isSignedByAnyOf(string $message, string $rawSignature, array $publicKeys): bool
    {
        if ('' === $rawSignature) {
            return false;
        }

        foreach ($publicKeys as $publicKey) {
            $rawKey = base64_decode($publicKey, true);
            if (false !== $rawKey && \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === \strlen($rawKey) && sodium_crypto_sign_verify_detached($rawSignature, $message, $rawKey)) {
                return true;
            }
        }

        return false;
    }
}
