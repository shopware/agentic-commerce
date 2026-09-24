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

    /**
     * @return StaticEntityRepository<TaxCollection>
     */
    public static function repository(): StaticEntityRepository
    {
        $tax = new TaxEntity();
        $tax->setId(self::TAX_ID);
        $tax->setUniqueIdentifier(self::TAX_ID);
        $tax->setTaxRate(19.0);

        /** @var StaticEntityRepository<TaxCollection> $repository */
        $repository = new StaticEntityRepository([new TaxCollection([$tax])]);

        return $repository;
    }
}
