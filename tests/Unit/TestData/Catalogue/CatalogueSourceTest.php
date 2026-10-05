<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Catalogue;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Swag\AgenticCommerce\TestData\Catalogue\CatalogueSource;
use Swag\AgenticCommerce\TestData\TestDataException;

/**
 * @internal
 */
#[CoversClass(CatalogueSource::class)]
class CatalogueSourceTest extends TestCase
{
    private const BASE_URL = 'https://example.test/releases/latest/download/';

    private string $cacheDirectory;

    private string $archiveBytes;

    /** @var array{publicKey: string, secretKey: non-empty-string} */
    private array $keyPair;

    /** @var array{index: string, signature: string} */
    private array $signedIndex;

    private MockHandler $handler;

    /** @var list<string> */
    private array $requestedUrls = [];

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir().'/swag-ac-catalogue-source-'.bin2hex(random_bytes(4));
        $zipPath = CatalogueFixture::writeZip($this->cacheDirectory.'.zip', CatalogueFixture::catalogue());
        $this->archiveBytes = (string) file_get_contents($zipPath);
        $this->keyPair = CatalogueFixture::keyPair();
        $this->signedIndex = CatalogueFixture::signedIndex($zipPath, $this->keyPair['secretKey']);
        unlink($zipPath);
        $this->handler = new MockHandler();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->cacheDirectory);
    }

    public function testItDownloadsAndVerifiesTheLatestRelease(): void
    {
        $this->queueIndex();
        $this->handler->append(new Response(200, [], $this->archiveBytes));
        $source = $this->source();

        $release = $source->fetchLatestRelease();
        $archivePath = $source->downloadArchive($release);

        static::assertSame([self::BASE_URL.'index.json', self::BASE_URL.'index.json.sig', self::BASE_URL.CatalogueFixture::ARCHIVE], $this->requestedUrls);
        static::assertSame($this->cacheDirectory.'/'.CatalogueFixture::ARCHIVE, $archivePath);
        static::assertSame($this->archiveBytes, file_get_contents($archivePath));
        static::assertSame($this->signedIndex['index'], file_get_contents($archivePath.'.index.json'));
        static::assertTrue($source->isArchiveCached($release));
    }

    public function testACachedArchiveIsReusedWithoutDownloading(): void
    {
        $this->queueIndex();
        $this->handler->append(new Response(200, [], $this->archiveBytes));
        $this->queueIndex();
        $source = $this->source();
        $source->downloadArchive($source->fetchLatestRelease());

        $source->downloadArchive($source->fetchLatestRelease());

        static::assertCount(5, $this->requestedUrls);
    }

    public function testAnArchiveThatDoesNotMatchTheIndexIsDiscarded(): void
    {
        $this->queueIndex();
        $this->handler->append(new Response(200, [], $this->archiveBytes.'tampered'));
        $source = $this->source();
        $release = $source->fetchLatestRelease();

        try {
            $source->downloadArchive($release);
            static::fail('A tampered archive must not be accepted.');
        } catch (TestDataException $exception) {
            static::assertStringContainsString('does not match the size and SHA-256', $exception->getMessage());
        }

        static::assertSame([], glob($this->cacheDirectory.'/*'));
    }

    public function testAnUnreachableSourceReportsTheCatalogueAsUnavailable(): void
    {
        $this->handler->append(new ConnectException('Could not resolve host', new Request('GET', self::BASE_URL.'index.json')));

        $this->expectExceptionObject(TestDataException::catalogueUnavailable('Could not resolve host'));

        $this->source()->fetchLatestRelease();
    }

    public function testAnUnwritableCacheDirectoryReportsTheCatalogueAsUnavailable(): void
    {
        $this->queueIndex();
        $this->handler->append(new Response(200, [], $this->archiveBytes));
        file_put_contents($this->cacheDirectory.'.blocker', '');
        $this->cacheDirectory .= '.blocker/cache';
        $source = $this->source();

        try {
            $source->downloadArchive($source->fetchLatestRelease());
            static::fail('A cache directory that cannot be created must not pass.');
        } catch (TestDataException $exception) {
            static::assertStringStartsWith('The product catalogue could not be fetched: ', $exception->getMessage());
            static::assertStringContainsString($this->cacheDirectory, $exception->getMessage());
        } finally {
            unlink(substr($this->cacheDirectory, 0, -\strlen('/cache')));
        }
    }

    public function testTheNewestVerifiedCacheEntryIsTheOfflineFallback(): void
    {
        $this->queueIndex();
        $this->handler->append(new Response(200, [], $this->archiveBytes));
        $source = $this->source();
        $source->downloadArchive($source->fetchLatestRelease());

        static::assertSame('1.0.0', $source->newestCachedRelease()?->version);
        static::assertNull($this->source([CatalogueFixture::keyPair()['publicKey']])->newestCachedRelease(), 'A cache signed by an unpinned key is ignored.');

        file_put_contents($this->cacheDirectory.'/'.CatalogueFixture::ARCHIVE, 'swapped');
        static::assertNull($source->newestCachedRelease(), 'An archive that no longer matches its index is ignored.');
    }

    private function queueIndex(): void
    {
        $this->handler->append(new Response(200, [], $this->signedIndex['index']), new Response(200, [], $this->signedIndex['signature']));
    }

    /**
     * @param ?list<string> $publicKeys
     */
    private function source(?array $publicKeys = null): CatalogueSource
    {
        $handlerStack = HandlerStack::create($this->handler);
        $handlerStack->push(function (callable $next): callable {
            return function (RequestInterface $request, array $options) use ($next) {
                $this->requestedUrls[] = (string) $request->getUri();

                return $next($request, $options);
            };
        });

        return new CatalogueSource(new Client(['handler' => $handlerStack]), $this->cacheDirectory, self::BASE_URL, $publicKeys ?? [$this->keyPair['publicKey']]);
    }
}
