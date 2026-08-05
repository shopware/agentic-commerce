<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\EscaperExtension;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * Renders the shipped OpenAI and Google body templates the way the product export
 * does and asserts the per-provider output: Google emits `<g:product_detail>` and
 * combined measurement fields, while OpenAI emits measurements as separate
 * value/unit fields and never emits `product_details` (no spec equivalent). Guards
 * against template regressions on every lane without a database.
 *
 * @internal
 */
#[CoversNothing]
class EssentialCharacteristicsFeedRenderTest extends TestCase
{
    private const TEMPLATE_DIR = __DIR__
        .'/../../../../src/Resources/app/administration/src/extension/sw-sales-channel'
        .'/agentic-product-export-templates';

    /**
     * @var list<array{section: string, name: string, value: string}>
     */
    private const CHARACTERISTICS = [
        ['section' => 'Specifications', 'name' => 'Weight', 'value' => '1.5 kg'],
        ['section' => 'Specifications', 'name' => 'Colour', 'value' => 'Red, Blue'],
    ];

    private const MEASUREMENTS = [
        'weight' => ['value' => '1000', 'unit' => 'kg', 'display' => '1000 kg'],
        'length' => ['value' => '10', 'unit' => 'cm', 'display' => '10 cm'],
        'width' => ['value' => '64.2', 'unit' => 'cm', 'display' => '64.2 cm'],
        'height' => ['value' => '82.5', 'unit' => 'cm', 'display' => '82.5 cm'],
        'unitPricingMeasure' => null,
        'unitPricingBaseMeasure' => null,
    ];

    private const NO_MEASUREMENTS = [
        'weight' => null,
        'length' => null,
        'width' => null,
        'height' => null,
        'unitPricingMeasure' => null,
        'unitPricingBaseMeasure' => null,
    ];

    public function testOpenAiRowNeverEmitsProductDetails(): void
    {
        // OpenAI's feed spec has no product_detail equivalent, so essential
        // characteristics must not be written to the OpenAI row.
        $output = $this->render('open-ai/body.json.twig', false);

        $row = json_decode(trim($output), true, 512, \JSON_THROW_ON_ERROR);

        static::assertIsArray($row);
        static::assertArrayNotHasKey('product_details', $row);
        static::assertArrayNotHasKey('product_weight', $row);
    }

    public function testOpenAiRowOmitsMeasurementsWhenEmpty(): void
    {
        $output = $this->render('open-ai/body.json.twig', false, [], self::NO_MEASUREMENTS);

        $row = json_decode(trim($output), true, 512, \JSON_THROW_ON_ERROR);

        static::assertIsArray($row);
        foreach (['weight', 'item_weight_unit', 'length', 'width', 'height', 'dimensions_unit'] as $key) {
            static::assertArrayNotHasKey($key, $row);
        }
    }

    public function testOpenAiRowEmitsMeasurementsAsSeparateValueAndUnit(): void
    {
        $output = $this->render('open-ai/body.json.twig', false);

        $row = json_decode(trim($output), true, 512, \JSON_THROW_ON_ERROR);

        static::assertIsArray($row);
        static::assertSame('1000', $row['weight']);
        static::assertSame('kg', $row['item_weight_unit']);
        static::assertSame('10', $row['length']);
        static::assertSame('64.2', $row['width']);
        static::assertSame('82.5', $row['height']);
        static::assertSame('cm', $row['dimensions_unit']);
    }

    public function testOpenAiParentRowMarksListingWithVariantsWithoutVariantValues(): void
    {
        $output = $this->render('open-ai/body.json.twig', false, self::CHARACTERISTICS, self::MEASUREMENTS, 2);

        $row = json_decode(trim($output), true, 512, \JSON_THROW_ON_ERROR);

        static::assertIsArray($row);
        static::assertTrue($row['listing_has_variations']);
        static::assertSame('product-id', $row['group_id']);
        static::assertArrayNotHasKey('variant_dict', $row);
    }

    public function testGoogleItemEmitsProductDetailElements(): void
    {
        $output = $this->render('google/body.xml.twig', 'html');

        $xml = '<rss xmlns:g="http://base.google.com/ns/1.0"><channel>'.$output.'</channel></rss>';

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        static::assertTrue($loaded, "Rendered Google item is not well-formed XML:\n{$output}");

        $details = $dom->getElementsByTagNameNS('http://base.google.com/ns/1.0', 'product_detail');
        static::assertCount(2, $details);

        $first = $details->item(0);
        static::assertNotNull($first);
        static::assertSame('Specifications', $first->getElementsByTagNameNS('http://base.google.com/ns/1.0', 'section_name')->item(0)?->textContent);
        static::assertSame('Weight', $first->getElementsByTagNameNS('http://base.google.com/ns/1.0', 'attribute_name')->item(0)?->textContent);
        static::assertSame('1.5 kg', $first->getElementsByTagNameNS('http://base.google.com/ns/1.0', 'attribute_value')->item(0)?->textContent);
    }

    public function testGoogleItemEmitsMeasurementFields(): void
    {
        $output = $this->render('google/body.xml.twig', 'html');

        $xml = '<rss xmlns:g="http://base.google.com/ns/1.0"><channel>'.$output.'</channel></rss>';

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        static::assertTrue($loaded, "Rendered Google item is not well-formed XML:\n{$output}");
        $ns = 'http://base.google.com/ns/1.0';

        static::assertSame('1000 kg', $dom->getElementsByTagNameNS($ns, 'product_weight')->item(0)?->textContent);
        static::assertSame('10 cm', $dom->getElementsByTagNameNS($ns, 'product_length')->item(0)?->textContent);
        static::assertSame('64.2 cm', $dom->getElementsByTagNameNS($ns, 'product_width')->item(0)?->textContent);
        static::assertSame('82.5 cm', $dom->getElementsByTagNameNS($ns, 'product_height')->item(0)?->textContent);
    }

    /**
     * @param list<array{section: string, name: string, value: string}>               $characteristics
     * @param array<string, array{value: string, unit: string, display: string}|null> $measurements
     */
    private function render(
        string $template,
        string|false $strategy,
        array $characteristics = self::CHARACTERISTICS,
        array $measurements = self::MEASUREMENTS,
        int $childCount = 0,
    ): string {
        $source = trim($this->readTemplate($template));
        $name = str_replace('/', '_', $template).'_'.(false === $strategy ? 'raw' : $strategy);

        $twig = new Environment(new ArrayLoader([$name => $source]));
        $twig->getExtension(EscaperExtension::class)->setDefaultStrategy($strategy);
        $twig->addFunction(new TwigFunction('seoUrl', static fn (): string => 'https://shop.test/detail'));
        $twig->addFunction(new TwigFunction(
            'agentic_essential_characteristics',
            static fn (mixed $product, mixed $context): array => $characteristics
        ));
        $twig->addFunction(new TwigFunction(
            'agentic_product_measurements',
            static fn (mixed $product): array => $measurements
        ));

        return $twig->render($name, $this->renderData($childCount));
    }

    /**
     * @return array<string, mixed>
     */
    private function renderData(int $childCount = 0): array
    {
        return [
            'product' => [
                'translated' => ['name' => 'Test product', 'description' => 'A description'],
                'name' => 'Test product',
                'calculatedPrice' => ['unitPrice' => 9.99, 'listPrice' => null],
                'calculatedPrices' => [],
                'cover' => ['media' => ['url' => 'https://shop.test/media/cover.jpg']],
                'media' => [],
                'coverId' => 'cover-id',
                'parentId' => null,
                'childCount' => $childCount,
                'productNumber' => 'SW-1',
                'id' => 'product-id',
                'manufacturer' => null,
                'ean' => '',
                'manufacturerNumber' => '',
                'available' => true,
                'restockTime' => null,
                'downloads' => [],
                'shippingFree' => false,
                'categories' => [],
                'options' => [],
                'sortedProperties' => [],
            ],
            'productExport' => ['includeVariants' => false],
            'provider' => [
                'referringSalesChannel' => 'sales-channel-id',
                'affiliateCode' => null,
                'campaignCode' => null,
                'isEligibleSearch' => true,
                'isEligibleCheckout' => false,
                'sellerName' => 'Merchant',
                'sellerUrl' => 'https://shop.test',
                'returnPolicyUrl' => 'https://shop.test/returns',
                'storeCountry' => 'DE',
                'targetCountries' => [],
                'shippingCountry' => 'DE',
                'shippingService' => null,
            ],
            'context' => [
                'currency' => ['isoCode' => 'EUR', 'itemRounding' => ['decimals' => 2]],
            ],
        ];
    }

    private function readTemplate(string $name): string
    {
        $contents = file_get_contents(self::TEMPLATE_DIR.'/'.$name);

        static::assertIsString($contents);

        return $contents;
    }
}
