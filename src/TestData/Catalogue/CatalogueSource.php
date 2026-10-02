<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Catalogue;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\TestDataException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
#[Package('framework')]
final class CatalogueSource
{
    public const BASE_URL = 'https://github.com/shopwareLabs/agentic-commerce-test-product-catalogue/releases/latest/download/';
    /** A key rotation ships the next key here before the release workflow signs with it. */
    public const PUBLIC_KEYS = ['9L5lJ59cUxx4MK8Agcto0Qp3KsB302DmzyaynyYSER4='];
    public const MAX_ARCHIVE_BYTES = 200 * 1024 * 1024;

    private const INDEX_FILE = 'index.json';
    private const SIGNATURE_FILE = 'index.json.sig';

    private readonly Filesystem $filesystem;

    /**
     * @param list<string> $publicKeys base64 raw Ed25519 keys; a release signed by any of them is accepted
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $cacheDirectory,
        private readonly string $baseUrl = self::BASE_URL,
        private readonly array $publicKeys = self::PUBLIC_KEYS,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function fetchLatestRelease(): CatalogueRelease
    {
        return CatalogueRelease::fromSignedIndex($this->fetchReleaseFile(self::INDEX_FILE), $this->fetchReleaseFile(self::SIGNATURE_FILE), $this->publicKeys, self::MAX_ARCHIVE_BYTES);
    }

    public function isArchiveCached(CatalogueRelease $release): bool
    {
        $archivePath = $this->archivePath($release);

        return is_file($archivePath) && filesize($archivePath) === $release->archiveBytes && hash_file('sha256', $archivePath) === $release->archiveSha256;
    }

    public function downloadArchive(CatalogueRelease $release): string
    {
        $archivePath = $this->archivePath($release);
        if ($this->isArchiveCached($release)) {
            return $archivePath;
        }

        $partialArchivePath = $archivePath.'.part';
        try {
            $this->filesystem->mkdir($this->cacheDirectory, 0o700);
            $this->httpClient->request('GET', $this->baseUrl.$release->archiveName, [
                'sink' => $partialArchivePath,
                'connect_timeout' => 10,
                'timeout' => 600,
                // Stops a response that outgrows the signed size before it fills the disk.
                'progress' => static function (int $downloadTotal, int $downloaded) use ($release): void {
                    if ($downloaded > $release->archiveBytes) {
                        throw TestDataException::catalogueArchiveMismatch($release->archiveName, 'is larger than index.json states');
                    }
                },
            ]);
        } catch (\Throwable $exception) {
            $this->filesystem->remove($partialArchivePath);

            throw $exception instanceof TestDataException ? $exception : TestDataException::catalogueUnavailable($exception->getMessage());
        }

        if (filesize($partialArchivePath) !== $release->archiveBytes || hash_file('sha256', $partialArchivePath) !== $release->archiveSha256) {
            $this->filesystem->remove($partialArchivePath);

            throw TestDataException::catalogueArchiveMismatch($release->archiveName, 'does not match the size and SHA-256 in index.json');
        }

        try {
            $this->filesystem->rename($partialArchivePath, $archivePath, true);
            $this->filesystem->dumpFile($archivePath.'.'.self::INDEX_FILE, $release->indexJson);
            $this->filesystem->dumpFile($archivePath.'.'.self::SIGNATURE_FILE, $release->signature);
        } catch (IOException $exception) {
            throw TestDataException::catalogueUnavailable($exception->getMessage());
        }

        return $archivePath;
    }

    public function newestCachedRelease(): ?CatalogueRelease
    {
        $newestRelease = null;
        foreach (glob($this->cacheDirectory.'/*.zip.'.self::INDEX_FILE) ?: [] as $indexPath) {
            $signaturePath = substr($indexPath, 0, -\strlen(self::INDEX_FILE)).self::SIGNATURE_FILE;
            try {
                $cachedRelease = CatalogueRelease::fromSignedIndex((string) file_get_contents($indexPath), (string) @file_get_contents($signaturePath), $this->publicKeys, self::MAX_ARCHIVE_BYTES);
            } catch (TestDataException) {
                continue;
            }

            if (basename($indexPath) === $cachedRelease->archiveName.'.'.self::INDEX_FILE && $this->isArchiveCached($cachedRelease) && (null === $newestRelease || version_compare($cachedRelease->version, $newestRelease->version, '>'))) {
                $newestRelease = $cachedRelease;
            }
        }

        return $newestRelease;
    }

    public function archivePath(CatalogueRelease $release): string
    {
        return $this->cacheDirectory.'/'.$release->archiveName;
    }

    private function fetchReleaseFile(string $file): string
    {
        try {
            return (string) $this->httpClient->request('GET', $this->baseUrl.$file, ['connect_timeout' => 10, 'timeout' => 30])->getBody();
        } catch (GuzzleException $exception) {
            throw TestDataException::catalogueUnavailable($exception->getMessage());
        }
    }
}
