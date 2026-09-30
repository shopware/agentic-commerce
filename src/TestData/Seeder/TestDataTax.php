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

    public function standardTax(Context $context): TaxEntity
    {
        $tax = $this->taxRepository->search((new Criteria())->addSorting(new FieldSorting('position'))->setLimit(1), $context)->getEntities()->first();
        if (null === $tax) {
            throw TestDataException::missingTax();
        }

        return $tax;
    }

    /**
     * The lowest positive rate below the standard one, which is what food and books take; the standard rate when
     * the shop has none.
     */
    public function reducedTax(TaxEntity $standardTax, Context $context): TaxEntity
    {
        $reduced = $standardTax;
        foreach ($this->taxRepository->search(new Criteria(), $context)->getEntities() as $tax) {
            if ($tax->getTaxRate() > 0 && $tax->getTaxRate() < $reduced->getTaxRate()) {
                $reduced = $tax;
            }
        }

        return $reduced;
    }

    /**
     * @return TestDataPrice
     */
    public static function grossAndNetPrice(float $gross, TaxEntity $tax): array
    {
        return [
            'currencyId' => Defaults::CURRENCY,
            'gross' => $gross,
            'net' => round($gross / (1 + $tax->getTaxRate() / 100), 2),
            'linked' => true,
        ];
    }
}
