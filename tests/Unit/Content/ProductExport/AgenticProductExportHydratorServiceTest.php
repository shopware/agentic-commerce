<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Content\ProductExport\AgenticProductExportHydrator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * @internal
 */
#[CoversNothing]
class AgenticProductExportHydratorServiceTest extends TestCase
{
    public function testHydratorIsPublicForDalRuntimeLookup(): void
    {
        $container = new ContainerBuilder();
        $loader = new PhpFileLoader($container, new FileLocator());
        $loader->load(__DIR__.'/../../../../src/Resources/config/services.php');

        // Core resolves the hydrator class from ProductExportDefinition::getHydratorClass()
        // through a runtime service_container lookup, so Symfony cannot infer the
        // reference during compilation. The hydrator must stay public for DAL hydration.
        static::assertTrue($container->getDefinition(AgenticProductExportHydrator::class)->isPublic());
    }
}
