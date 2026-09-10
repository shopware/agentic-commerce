<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Compatibility\Snippet;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Administration\Snippet\SnippetFinderInterface;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Compatibility\Snippet\CountryAgnosticSnippetFinder;

/** @internal */
#[CoversClass(CountryAgnosticSnippetFinder::class)]
final class CountryAgnosticSnippetFinderTest extends TestCase
{
    #[Test]
    public function testItStaysPassiveWhenCoreSupportsCountryAgnosticFilenames(): void
    {
        $innerResult = ['some' => ['core' => 'snippets']];
        $finder = new CountryAgnosticSnippetFinder(
            $this->createInner($innerResult),
            new ShopwareVersionDetector(versionOverride: '6.7.3.0'),
        );

        self::assertSame($innerResult, $finder->findSnippets('de-DE'));
    }

    #[Test]
    public function testItMergesThePluginSnippetFilesOnOlderVersions(): void
    {
        $finder = new CountryAgnosticSnippetFinder(
            $this->createInner(['some' => ['core' => 'snippets']]),
            new ShopwareVersionDetector(versionOverride: '6.6.10.0'),
        );

        $snippets = $finder->findSnippets('de-DE');

        self::assertSame('snippets', $snippets['some']['core']);
        self::assertSame(
            'Bereitgestellt',
            $snippets['swagAgenticCommerce']['salesChannelList']['ucpExposed'],
        );
        self::assertSame(
            'Exportkanal-Tracking',
            $snippets['sw-export-channel-tracking']['general']['mainMenuItemGeneral'],
        );
    }

    #[Test]
    public function testItResolvesTheLanguageFromTheFullLocale(): void
    {
        $finder = new CountryAgnosticSnippetFinder(
            $this->createInner([]),
            new ShopwareVersionDetector(versionOverride: '6.5.8.0'),
        );

        $snippets = $finder->findSnippets('en-US');

        self::assertSame(
            'Exposed',
            $snippets['swagAgenticCommerce']['salesChannelList']['ucpExposed'],
        );
    }

    #[Test]
    public function testItOverridesCoreValuesWithThePluginOnes(): void
    {
        $finder = new CountryAgnosticSnippetFinder(
            $this->createInner([
                'sw-sales-channel' => [
                    'modal' => ['messageNoProductStreams' => 'core default'],
                ],
            ]),
            new ShopwareVersionDetector(versionOverride: '6.6.10.0'),
        );

        $snippets = $finder->findSnippets('en-GB');

        self::assertNotSame('core default', $snippets['sw-sales-channel']['modal']['messageNoProductStreams']);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function createInner(array $result): SnippetFinderInterface
    {
        $inner = $this->createMock(SnippetFinderInterface::class);
        $inner->method('findSnippets')->willReturn($result);

        return $inner;
    }
}
