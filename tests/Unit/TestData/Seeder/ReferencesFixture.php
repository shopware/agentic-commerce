<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Seeder;

use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerCollection;
use Shopware\Core\Defaults;
use Shopware\Core\System\DeliveryTime\DeliveryTimeCollection;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\Unit\UnitCollection;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Swag\AgenticCommerce\TestData\Seeder\CategoryTreeIds;
use Swag\AgenticCommerce\TestData\Seeder\ProductReferences;
use Swag\AgenticCommerce\TestData\Seeder\TestDataTax;
use Swag\AgenticCommerce\TestData\ShopLanguagesLoader;

/**
 * @internal
 */
final class ReferencesFixture
{
    public const GERMAN_LANGUAGE_ID = '0191ffffffff7000ffffffffffffffff';
    public const NAVIGATION_ROOT_ID = '0191abababab7000abababababababab';
    /** More lookups than any test makes; a StaticEntityRepository fails once its queued results run out. */
    private const SEARCHES = 50;

    /** @var StaticEntityRepository<ProductManufacturerCollection> */
    public readonly StaticEntityRepository $manufacturerRepository;

    /** @var StaticEntityRepository<UnitCollection> */
    public readonly StaticEntityRepository $unitRepository;

    /** @var StaticEntityRepository<DeliveryTimeCollection> */
    public readonly StaticEntityRepository $deliveryTimeRepository;

    public readonly ProductReferences $productReferences;

    public function __construct(bool $hasGerman = false)
    {
        $this->manufacturerRepository = new StaticEntityRepository(array_fill(0, self::SEARCHES, []));
        $this->unitRepository = new StaticEntityRepository(array_fill(0, self::SEARCHES, []));
        $this->deliveryTimeRepository = new StaticEntityRepository(array_fill(0, self::SEARCHES, []));
        $this->productReferences = new ProductReferences(
            $this->manufacturerRepository,
            $this->unitRepository,
            $this->deliveryTimeRepository,
            new TestDataTax(TaxFixture::repository(self::SEARCHES)),
            self::shopLanguagesLoader($hasGerman),
            self::categoryTreeIds(),
        );
    }

    public static function categoryTreeIds(): CategoryTreeIds
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId('0191aaaaaaaa7000aaaaaaaaaaaaaaaa');
        $salesChannel->setUniqueIdentifier('0191aaaaaaaa7000aaaaaaaaaaaaaaaa');
        $salesChannel->setNavigationCategoryId(self::NAVIGATION_ROOT_ID);

        /** @var StaticEntityRepository<SalesChannelCollection> $salesChannelRepository */
        $salesChannelRepository = new StaticEntityRepository(array_fill(0, self::SEARCHES, new SalesChannelCollection([$salesChannel])));

        return new CategoryTreeIds($salesChannelRepository);
    }

    public static function shopLanguagesLoader(bool $hasGerman = false): ShopLanguagesLoader
    {
        $languages = [self::language(Defaults::LANGUAGE_SYSTEM, 'en-GB')];
        if ($hasGerman) {
            $languages[] = self::language(self::GERMAN_LANGUAGE_ID, 'de-DE');
        }

        /** @var StaticEntityRepository<LanguageCollection> $languageRepository */
        $languageRepository = new StaticEntityRepository(array_fill(0, self::SEARCHES, new LanguageCollection($languages)));

        return new ShopLanguagesLoader($languageRepository);
    }

    private static function language(string $id, string $localeCode): LanguageEntity
    {
        $locale = new LocaleEntity();
        $locale->setId(md5($localeCode));
        $locale->setCode($localeCode);

        $language = new LanguageEntity();
        $language->setId($id);
        $language->setUniqueIdentifier($id);
        $language->setLocale($locale);

        return $language;
    }
}
