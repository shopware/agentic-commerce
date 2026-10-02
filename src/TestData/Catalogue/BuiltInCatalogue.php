<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Catalogue;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\Seeder\DynamicAccessSeeder;
use Swag\AgenticCommerce\TestData\Seeder\FoundationSeeder;
use Swag\AgenticCommerce\TestData\Seeder\ProductSeeder;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * The offline data set: fixed products without images, in the same shape as catalogue products.
 *
 * @internal
 */
#[Package('framework')]
final class BuiltInCatalogue
{
    public static function pickedProducts(): PickedProducts
    {
        $productByRole = [
            ProductSeeder::PHYSICAL_WITH_DIGITAL_OPTION => new CatalogueProduct(
                'notebook',
                CatalogueProduct::TYPE_PHYSICAL,
                new TranslatedText('Notebook', 'Notizbuch'),
                19.99,
                100,
                description: new TranslatedText('Physical product with two printed variants and one PDF variant.', 'Physisches Produkt mit zwei gedruckten Varianten und einer PDF-Variante.'),
                categoryKeys: ['stationery'],
                weight: 0.35,
                width: 148.0,
                height: 210.0,
                length: 12.0,
                variantPropertyGroup: 'format',
                variantOptions: [
                    new CatalogueVariantOption('a5', new TranslatedText('Printed A5', 'Gedruckt, A5'), CatalogueProduct::TYPE_PHYSICAL, null, 0.0, 100),
                    new CatalogueVariantOption('a4', new TranslatedText('Printed A4', 'Gedruckt, A4'), CatalogueProduct::TYPE_PHYSICAL, null, 5.0, 100, weight: 0.6, dimensions: ['width' => 210.0, 'height' => 297.0]),
                    new CatalogueVariantOption('pdf', new TranslatedText('PDF', 'PDF'), CatalogueProduct::TYPE_DIGITAL, null, -10.0, 1000),
                ],
            ),
            ProductSeeder::DIGITAL_WITH_PHYSICAL_OPTION => new CatalogueProduct(
                'album',
                CatalogueProduct::TYPE_DIGITAL,
                new TranslatedText('Album', 'Album'),
                12.99,
                100,
                description: new TranslatedText('Digital product with two download variants and one vinyl variant.', 'Digitales Produkt mit zwei Download-Varianten und einer Schallplattenvariante.'),
                variantPropertyGroup: 'format',
                variantOptions: [
                    new CatalogueVariantOption('mp3', new TranslatedText('MP3', 'MP3'), CatalogueProduct::TYPE_DIGITAL, null, -3.0, 1000),
                    new CatalogueVariantOption('flac', new TranslatedText('FLAC', 'FLAC'), CatalogueProduct::TYPE_DIGITAL, null, 0.0, 1000),
                    new CatalogueVariantOption('vinyl', new TranslatedText('Vinyl', 'Schallplatte'), CatalogueProduct::TYPE_PHYSICAL, null, 17.0, 20, weight: 0.25),
                ],
                downloadMediaKey: FoundationSeeder::MEDIA_ALBUM,
                categoryKeys: ['music'],
            ),
            ProductSeeder::TIER_PRICES => new CatalogueProduct(
                'bulk-pens',
                CatalogueProduct::TYPE_PHYSICAL,
                new TranslatedText('Bulk Pens', 'Kugelschreiber zum Staffelpreis'),
                29.99,
                100,
                description: new TranslatedText('Advanced prices by quantity: 1–4, 5–9 and 10 or more.', 'Erweiterte Preise nach Menge: 1–4, 5–9 und ab 10 Stück.'),
                categoryKeys: ['stationery'],
                weight: 0.1,
            ),
            ProductSeeder::CUSTOM_FIELDS => new CatalogueProduct(
                'linen-tote-bag',
                CatalogueProduct::TYPE_PHYSICAL,
                new TranslatedText('Linen Tote Bag', 'Leinentasche'),
                14.99,
                100,
                description: new TranslatedText('Carries values for every field of the test custom field set.', 'Enthält Werte für alle Felder des Test-Zusatzfeld-Sets.'),
                categoryKeys: ['bags'],
                weight: 0.2,
                customFieldByName: ['care_note' => new TranslatedText('Wash cold, dry flat.', 'Kalt waschen, liegend trocknen.'), 'warranty_years' => 2, 'recyclable' => true],
            ),
            ProductSeeder::PROPERTIES => new CatalogueProduct(
                'linen-notebook-cover',
                CatalogueProduct::TYPE_PHYSICAL,
                new TranslatedText('Linen Notebook Cover', 'Notizbuchhülle aus Leinen'),
                17.99,
                100,
                description: new TranslatedText('Carries properties that are not variant options.', 'Enthält Eigenschaften, die keine Variantenoptionen sind.'),
                categoryKeys: ['stationery'],
                weight: 0.15,
                optionKeysByGroup: ['material' => ['recycled-paper', 'linen']],
            ),
            DynamicAccessSeeder::MEMBERS_ONLY_PRODUCT => new CatalogueProduct(
                'members-only-notebook',
                CatalogueProduct::TYPE_PHYSICAL,
                new TranslatedText('Members-only Notebook', 'Notizbuch nur für Mitglieder'),
                39.99,
                100,
                description: new TranslatedText('Restricted by Dynamic Access to logged-in customers.', 'Durch Dynamic Access nur für angemeldete Kunden sichtbar.'),
                categoryKeys: ['stationery'],
                weight: 0.35,
            ),
        ];

        return new PickedProducts(new Catalogue(array_values($productByRole), [
            'format' => ['name' => new TranslatedText('Format', 'Format'), 'display' => 'text', 'options' => []],
            'material' => ['name' => new TranslatedText('Material', 'Material'), 'display' => 'text', 'options' => ['recycled-paper' => new TranslatedText('Recycled paper', 'Recyclingpapier'), 'linen' => new TranslatedText('Linen', 'Leinen')]],
        ], categoryNameByKey: [
            'stationery' => new TranslatedText('Stationery', 'Schreibwaren'),
            'music' => new TranslatedText('Music', 'Musik'),
            'bags' => new TranslatedText('Bags', 'Taschen'),
        ]), $productByRole);
    }
}
