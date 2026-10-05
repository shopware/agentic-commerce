<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Catalogue;

/**
 * @internal
 */
final class CatalogueFixture
{
    public const VERSION = '1.0.0';
    public const ARCHIVE = 'test-product-catalogue-1.0.0.zip';

    /**
     * @return array<string, mixed>
     */
    public static function catalogue(): array
    {
        return [
            'schema' => 1,
            'version' => self::VERSION,
            'licence' => 'CC0-1.0',
            'propertyGroups' => [
                'colour' => ['name' => ['en' => 'Colour', 'de' => 'Farbe'], 'display' => 'color', 'options' => []],
                'format' => ['name' => ['en' => 'Format', 'de' => 'Format'], 'display' => 'text', 'options' => []],
                'material' => ['name' => ['en' => 'Material', 'de' => 'Material'], 'display' => 'text', 'options' => [
                    'glass' => ['name' => ['en' => 'Glass', 'de' => 'Glas']],
                    'ceramic' => ['name' => ['en' => 'Ceramic', 'de' => 'Keramik']],
                ]],
            ],
            'categories' => [
                'toys-games' => ['name' => ['en' => 'Toys & Games', 'de' => 'Spielwaren']],
                'food-drink' => ['name' => ['en' => 'Food & Drink', 'de' => 'Lebensmittel & Getränke']],
                'curiosities-gifts' => ['name' => ['en' => 'Curiosities & Gifts', 'de' => 'Kuriositäten & Geschenke']],
            ],
            'manufacturers' => [
                'pocket-meadow' => ['name' => 'Pocket Meadow Toys', 'description' => ['en' => 'Soft toys.', 'de' => 'Kuscheltiere.']],
            ],
            'products' => [
                self::product('knitted-bunny', 'physical', [
                    'manufacturer' => 'pocket-meadow',
                    'weight' => 0.2, 'width' => 120, 'height' => 250, 'length' => 90,
                    'deliveryTime' => ['min' => 1, 'max' => 3, 'unit' => 'day'],
                    'customFields' => ['care_note' => ['en' => 'Hand wash.', 'de' => 'Handwäsche.'], 'warranty_years' => 1],
                    'variants' => ['group' => 'colour', 'options' => [
                        self::option('cream', 'physical', ['color' => '#F3EAD8']),
                        self::option('pink', 'physical', ['color' => '#F4B6C2', 'priceDelta' => 2.0, 'image' => 'images/knitted-bunny/variant-pink.webp']),
                        self::option('pattern-pdf', 'digital', ['priceDelta' => -20.0, 'stock' => 1000]),
                    ]],
                ], ['cover', 'lifestyle', 'scale', 'variant-pink']),
                self::product('map-scroll', 'digital', [
                    'variants' => ['group' => 'format', 'options' => [
                        self::option('download', 'digital', ['stock' => 1000]),
                        self::option('parchment', 'physical', ['priceDelta' => 22.0, 'weight' => 0.3, 'deliveryTime' => ['min' => 2, 'max' => 5, 'unit' => 'day']]),
                    ]],
                ]),
                self::product('plush-fox', 'physical', ['weight' => 0.2, 'variants' => ['group' => 'colour', 'options' => [
                    self::option('orange', 'physical', ['color' => '#E8742A']),
                    self::option('grey', 'physical', ['color' => '#9A9DA3', 'image' => 'images/plush-fox/variant-grey.webp']),
                ]]], ['cover', 'lifestyle', 'scale', 'variant-grey']),
                self::product('frog-umbrella', 'physical', ['weight' => 0.4, 'variants' => ['group' => 'colour', 'options' => [
                    self::option('green', 'physical', ['color' => '#4CAF50']),
                    self::option('yellow', 'physical', ['color' => '#F5C518']),
                ]]]),
                self::product('wildflower-honey', 'physical', [
                    'tax' => 'reduced',
                    'listPrice' => 12.99,
                    'weight' => 0.7,
                    'unit' => ['code' => 'kg', 'purchase' => 0.5, 'reference' => 1],
                    'purchase' => ['min' => 1, 'max' => 10, 'steps' => 1],
                    'properties' => ['material' => ['glass']],
                    'customFields' => ['ingredients' => ['en' => 'Honey.', 'de' => 'Honig.'], 'allergens' => ['en' => 'None.', 'de' => 'Keine.'], 'recyclable' => true],
                    'crossSelling' => ['cat-mug', 'rune-stones'],
                ]),
                self::product('cat-mug', 'physical', [
                    'weight' => 0.4,
                    'properties' => ['material' => ['ceramic']],
                    'customFields' => ['care_note' => ['en' => 'Dishwasher safe.', 'de' => 'Spülmaschinenfest.'], 'warranty_years' => 2, 'recyclable' => false],
                    'crossSelling' => ['wildflower-honey'],
                ]),
                self::product('rune-stones', 'physical', ['weight' => 0.3]),
                self::product('candle-lantern', 'physical', ['weight' => 0.9]),
            ],
        ];
    }

    /**
     * @param array<string, mixed>      $catalogue
     * @param array<string, string>     $extraEntries archive entries the catalogue does not list
     * @param ?\Closure(string): string $imageBytes   the bytes per image file; placeholders by default
     */
    public static function writeZip(string $path, array $catalogue, array $extraEntries = [], ?\Closure $imageBytes = null): string
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('catalogue.json', (string) json_encode($catalogue, \JSON_THROW_ON_ERROR));
        foreach ($catalogue['products'] ?? [] as $product) {
            foreach ($product['images'] as $image) {
                $zip->addFromString($image['file'], ($imageBytes ?? self::imageBytes(...))($image['file']));
            }
        }
        foreach ($extraEntries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public static function imageBytes(string $file): string
    {
        return 'RIFF0000WEBP'.$file;
    }

    /**
     * @return array{publicKey: string, secretKey: non-empty-string} the public key base64 as the plugin pins it, the secret key raw
     */
    public static function keyPair(): array
    {
        $keyPair = sodium_crypto_sign_keypair();

        return ['publicKey' => base64_encode(sodium_crypto_sign_publickey($keyPair)), 'secretKey' => sodium_crypto_sign_secretkey($keyPair)];
    }

    /**
     * @param non-empty-string     $secretKey
     * @param array<string, mixed> $overrides
     *
     * @return array{index: string, signature: string}
     */
    public static function signedIndex(string $archivePath, string $secretKey, array $overrides = []): array
    {
        $index = (string) json_encode([
            'schema' => 1,
            'version' => self::VERSION,
            'archive' => self::ARCHIVE,
            'size' => filesize($archivePath),
            'sha256' => hash_file('sha256', $archivePath),
            'created' => '2026-09-25T12:00:00Z',
            ...$overrides,
        ], \JSON_THROW_ON_ERROR);

        return ['index' => $index, 'signature' => base64_encode(sodium_crypto_sign_detached($index, $secretKey))];
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string>         $shots
     *
     * @return array<string, mixed>
     */
    private static function product(string $id, string $type, array $fields, array $shots = ['cover', 'lifestyle', 'scale']): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'gtin' => '2000000000001',
            'name' => ['en' => ucwords(str_replace('-', ' ', $id)), 'de' => 'DE '.$id],
            'description' => ['en' => '<p>'.$id.'</p>', 'de' => '<p>DE '.$id.'</p>'],
            'keywords' => ['en' => $id, 'de' => $id],
            'metaTitle' => ['en' => $id, 'de' => $id],
            'metaDescription' => ['en' => $id, 'de' => $id],
            'price' => 10.99,
            'stock' => 50,
            'crossSelling' => [],
            'categories' => ['wildflower-honey' === $id ? 'food-drink' : 'toys-games', 'curiosities-gifts'],
            'images' => array_map(static fn (string $shot): array => [
                'file' => 'images/'.$id.'/'.$shot.'.webp',
                'role' => str_starts_with($shot, 'variant-') ? 'variant' : $shot,
                'alt' => ['en' => $id.' '.$shot.', AI-generated', 'de' => $id.' '.$shot.', KI-generiert'],
                'model' => 'Qwen/Qwen-Image-2512',
            ], $shots),
            ...$fields,
        ];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function option(string $key, string $type, array $fields = []): array
    {
        return ['key' => $key, 'name' => ['en' => ucfirst($key), 'de' => 'DE '.$key], 'type' => $type, 'priceDelta' => 0.0, 'stock' => 20, 'image' => null, ...$fields];
    }
}
