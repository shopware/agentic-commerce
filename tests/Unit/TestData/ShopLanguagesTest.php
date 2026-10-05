<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Swag\AgenticCommerce\TestData\ShopLanguages;
use Swag\AgenticCommerce\TestData\ShopLanguagesLoader;
use Swag\AgenticCommerce\TestData\TranslatedText;
use Swag\AgenticCommerce\Tests\Unit\TestData\Seeder\ReferencesFixture;

/**
 * @internal
 */
#[CoversClass(ShopLanguages::class)]
#[CoversClass(ShopLanguagesLoader::class)]
class ShopLanguagesTest extends TestCase
{
    public function testTheSystemLanguageGetsTheRootFieldsAndGermanATranslation(): void
    {
        $shopLanguages = ReferencesFixture::shopLanguagesLoader(hasGerman: true)->load(Context::createDefaultContext());

        static::assertSame([
            'name' => 'Plush Fox',
            'description' => '<p>Soft.</p>',
            'translations' => [ReferencesFixture::GERMAN_LANGUAGE_ID => ['name' => 'Plüschfuchs', 'description' => '<p>Weich.</p>']],
        ], $shopLanguages->translatedFields(['name' => new TranslatedText('Plush Fox', 'Plüschfuchs'), 'description' => new TranslatedText('<p>Soft.</p>', '<p>Weich.</p>'), 'keywords' => null]));
    }

    public function testAShopWithoutGermanGetsOnlyTheRootFields(): void
    {
        $shopLanguages = ReferencesFixture::shopLanguagesLoader()->load(Context::createDefaultContext());

        static::assertSame(['name' => 'Plush Fox'], $shopLanguages->translatedFields(['name' => new TranslatedText('Plush Fox', 'Plüschfuchs')]));
    }

    public function testAGermanSystemLanguageTakesTheGermanTextsAtTheRoot(): void
    {
        $shopLanguages = new ShopLanguages(ShopLanguages::GERMAN, [ShopLanguages::ENGLISH => 'english-id']);

        static::assertSame(
            ['name' => 'Plüschfuchs', 'translations' => ['english-id' => ['name' => 'Plush Fox']]],
            $shopLanguages->translatedFields(['name' => new TranslatedText('Plush Fox', 'Plüschfuchs')]),
        );
    }

    public function testCustomFieldTextsFollowTheLanguageAndOtherValuesRepeat(): void
    {
        $shopLanguages = new ShopLanguages(ShopLanguages::ENGLISH, [ShopLanguages::GERMAN => 'german-id']);

        static::assertSame(
            [
                'customFields' => ['care_note' => 'Wash cold.', 'warranty_years' => 2],
                'translations' => ['german-id' => ['customFields' => ['care_note' => 'Kalt waschen.', 'warranty_years' => 2]]],
            ],
            $shopLanguages->translatedFields([], ['care_note' => new TranslatedText('Wash cold.', 'Kalt waschen.'), 'warranty_years' => 2]),
        );
    }
}
