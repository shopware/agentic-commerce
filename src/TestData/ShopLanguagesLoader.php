<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\Language\LanguageCollection;

/**
 * @internal
 */
#[Package('framework')]
final class ShopLanguagesLoader
{
    private const LANGUAGE_BY_LOCALE = ['en-GB' => ShopLanguages::ENGLISH, 'de-DE' => ShopLanguages::GERMAN];

    /**
     * @param EntityRepository<LanguageCollection> $languageRepository
     */
    public function __construct(private readonly EntityRepository $languageRepository)
    {
    }

    public function load(Context $context): ShopLanguages
    {
        $systemLanguage = ShopLanguages::ENGLISH;
        $languageIdByLanguage = [];
        foreach ($this->languageRepository->search((new Criteria())->addAssociation('locale'), $context)->getEntities() as $language) {
            $catalogueLanguage = self::LANGUAGE_BY_LOCALE[$language->getLocale()?->getCode() ?? ''] ?? null;
            if (Defaults::LANGUAGE_SYSTEM === $language->getId()) {
                $systemLanguage = $catalogueLanguage ?? ShopLanguages::ENGLISH;
            } elseif (null !== $catalogueLanguage) {
                $languageIdByLanguage[$catalogueLanguage] ??= $language->getId();
            }
        }
        unset($languageIdByLanguage[$systemLanguage]);

        return new ShopLanguages($systemLanguage, $languageIdByLanguage);
    }
}
