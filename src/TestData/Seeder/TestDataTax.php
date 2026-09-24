<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\Tax\TaxCollection;
use Shopware\Core\System\Tax\TaxEntity;
use Swag\AgenticCommerce\TestData\TestDataException;

/**
 * @phpstan-type TestDataPrice array{currencyId: string, gross: float, net: float, linked: bool}
 *
 * @internal
 */
#[Package('framework')]
final class TestDataTax
{
    /**
     * @param EntityRepository<TaxCollection> $taxRepository
     */
    public function __construct(private readonly EntityRepository $taxRepository)
    {
    }

    public function resolve(Context $context): TaxEntity
    {
        $tax = $this->taxRepository->search((new Criteria())->addSorting(new FieldSorting('position'))->setLimit(1), $context)->getEntities()->first();
        if (null === $tax) {
            throw TestDataException::missingTax();
        }

        return $tax;
    }

    /**
     * @return TestDataPrice
     */
    public static function price(float $gross, TaxEntity $tax): array
    {
        return [
            'currencyId' => Defaults::CURRENCY,
            'gross' => $gross,
            'net' => round($gross / (1 + $tax->getTaxRate() / 100), 2),
            'linked' => true,
        ];
    }
}
