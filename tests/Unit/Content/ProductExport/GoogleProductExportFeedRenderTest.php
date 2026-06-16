<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\EscaperExtension;
use Twig\Loader\ArrayLoader;

/**
 * Renders the Google feed header the way core does (StringTemplateRenderer toggles
 * Twig autoescaping on or off) and asserts hostile sales-channel values stay valid
 * XML. Both strategies are covered because the escape default is the only renderer
 * setting that differs across the 6.5 / 6.6 / 6.7 lanes.
 *
 * @internal
 */
#[CoversNothing]
class GoogleProductExportFeedRenderTest extends TestCase
{
    private const TEMPLATE_DIR = __DIR__
        .'/../../../../src/Resources/app/administration/src/extension/sw-sales-channel'
        .'/agentic-product-export-templates/google';

    private const SALES_CHANNEL_NAME = 'Übér & Søns <"Agentic">';
    private const DOMAIN_URL = 'https://shop.test/feed?lang=de&x="1"';
    private const ACCESS_KEY = 'AC&<>"\'';
    private const FILE_NAME = 'feed&<>.xml';

    /**
     * @return array<string, array{0: 'html'|false}>
     */
    public static function escapeStrategyProvider(): array
    {
        return [
            'autoescape on (html)' => ['html'],
            'autoescape off' => [false],
        ];
    }

    #[DataProvider('escapeStrategyProvider')]
    public function testHeaderRendersWellFormedXmlForHostileValues(string|false $strategy): void
    {
        $xml = $this->renderFeed($strategy);

        static::assertStringContainsString('&amp;', $xml);
        static::assertStringContainsString('&lt;', $xml);

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        static::assertTrue(
            $loaded && [] === $errors,
            "Rendered feed is not well-formed XML.\nXML:\n{$xml}\nErrors: ".var_export($errors, true)
        );

        // Escaped values must decode back to the exact originals (incl. non-ASCII).
        static::assertSame(self::SALES_CHANNEL_NAME, $dom->getElementsByTagName('title')->item(0)?->textContent);
        static::assertSame(self::SALES_CHANNEL_NAME, $dom->getElementsByTagName('description')->item(0)?->textContent);
        // <link> lives in the null namespace; atom:link shares the local name.
        static::assertSame(self::DOMAIN_URL, $dom->getElementsByTagNameNS('', 'link')->item(0)?->textContent);

        $atomLink = $dom->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'link')->item(0);
        static::assertNotNull($atomLink);
        static::assertSame(
            self::DOMAIN_URL.'/store-api/product-export/'.self::ACCESS_KEY.'/'.self::FILE_NAME,
            $atomLink->getAttribute('href')
        );
    }

    private function renderFeed(string|false $strategy): string
    {
        $header = trim($this->readTemplate('header.xml.twig'));
        $footer = trim($this->readTemplate('footer.xml.twig'));

        // Name embeds the strategy (as core embeds $htmlEscape) so each row compiles
        // its own template class instead of reusing the other row's cached one.
        $name = 'header_'.(false === $strategy ? 'raw' : $strategy);
        $twig = new Environment(new ArrayLoader([$name => $header]));
        $twig->getExtension(EscaperExtension::class)->setDefaultStrategy($strategy);

        $data = [
            'productExport' => [
                'salesChannelDomain' => [
                    'url' => self::DOMAIN_URL,
                    'language' => ['locale' => ['code' => 'de-DE']],
                ],
                'accessKey' => self::ACCESS_KEY,
                'fileName' => self::FILE_NAME,
            ],
            'context' => [
                'salesChannel' => ['name' => self::SALES_CHANNEL_NAME],
            ],
        ];

        return $twig->render($name, $data)."\n".$footer;
    }

    private function readTemplate(string $name): string
    {
        $contents = file_get_contents(self::TEMPLATE_DIR.'/'.$name);

        static::assertIsString($contents);

        return $contents;
    }
}
