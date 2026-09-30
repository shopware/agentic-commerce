<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Seeder;

use Shopware\Core\System\Tax\TaxCollection;
use Shopware\Core\System\Tax\TaxEntity;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;

/**
 * @internal
 */
final class TaxFixture
{
    public const TAX_ID = '0191dddddddd7000dddddddddddddddd';
    public const REDUCED_TAX_ID = '0191eeeeeeee7000eeeeeeeeeeeeeeee';

    /**
     * @return StaticEntityRepository<TaxCollection>
     */
    public static function repository(int $searches = 1): StaticEntityRepository
    {
        /** @var StaticEntityRepository<TaxCollection> $repository */
        $repository = new StaticEntityRepository(array_fill(0, $searches, new TaxCollection([self::tax(self::TAX_ID, 19.0), self::tax(self::REDUCED_TAX_ID, 7.0)])));

        return $repository;
    }

    private static function tax(string $id, float $taxRate): TaxEntity
    {
        $tax = new TaxEntity();
        $tax->setId($id);
        $tax->setUniqueIdentifier($id);
        $tax->setTaxRate($taxRate);

        return $tax;
    }
}
