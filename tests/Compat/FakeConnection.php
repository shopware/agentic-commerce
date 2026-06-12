<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Compat;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver;

/**
 * Stand-in for Shopware 6.5's FakeConnection test helper.
 *
 * On 6.5.x, `StaticDefinitionInstanceRegistry` instantiates
 * `Shopware\Tests\Integration\Core\Checkout\Cart\Promotion\Helpers\Fakes\FakeConnection`,
 * which lives in Shopware's autoload-dev namespace. On CI, Shopware is installed
 * with --no-dev, so that class does not exist and every test using the registry
 * fails. {@see self::register()} aliases this class under the legacy name when
 * it is missing. From 6.6 on, the registry uses a stub from the production
 * autoload (`Shopware\Core\Test\Stub\Doctrine\FakeConnection`) and the alias is
 * never used.
 *
 * No query methods are overridden: the registry only passes the connection to
 * `CustomFieldService`, which does not query it in unit tests, and the method
 * signatures differ between DBAL 3 (Shopware runtime) and DBAL 4 (plugin tools).
 *
 * @internal
 */
class FakeConnection extends Connection
{
    private const LEGACY_CLASS = 'Shopware\Tests\Integration\Core\Checkout\Cart\Promotion\Helpers\Fakes\FakeConnection';

    /**
     * @param list<array<array-key, mixed>> $dbRows accepted for signature parity with the original, never queried
     *
     * @phpstan-ignore-next-line DBAL Connection uses psalm-consistent-constructor; mirroring Shopware's own FakeConnection here
     */
    public function __construct(array $dbRows)
    {
        parent::__construct(
            [
                'url' => 'sqlite:///:memory:',
            ],
            new Driver(),
            new Configuration()
        );
    }

    public static function register(): void
    {
        if (!class_exists(self::LEGACY_CLASS)) {
            class_alias(self::class, self::LEGACY_CLASS);
        }
    }
}
