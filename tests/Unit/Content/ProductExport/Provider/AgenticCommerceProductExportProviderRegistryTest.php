<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Content\ProductExport\Provider\AbstractAgenticCommerceProductExportProvider;
use Swag\AgenticCommerce\Content\ProductExport\Provider\AgenticCommerceProductExportProviderRegistry;

/**
 * @internal
 */
#[CoversClass(AgenticCommerceProductExportProviderRegistry::class)]
class AgenticCommerceProductExportProviderRegistryTest extends TestCase
{
    public function testGetByTechnicalNameReturnsMatchingProvider(): void
    {
        $firstProvider = $this->createProvider('google');
        $matchingProvider = $this->createProvider('open-ai');
        $duplicateProvider = $this->createProvider('open-ai');

        $registry = new AgenticCommerceProductExportProviderRegistry([
            $firstProvider,
            $matchingProvider,
            $duplicateProvider,
        ]);

        static::assertSame($matchingProvider, $registry->getByTechnicalName('open-ai'));
    }

    public function testGetByTechnicalNameReturnsNullWhenProviderDoesNotExist(): void
    {
        $registry = new AgenticCommerceProductExportProviderRegistry([
            $this->createProvider('google'),
            $this->createProvider('meta'),
        ]);

        static::assertNull($registry->getByTechnicalName('open-ai'));
    }

    private function createProvider(string $technicalName): AbstractAgenticCommerceProductExportProvider
    {
        return new class($technicalName) extends AbstractAgenticCommerceProductExportProvider {
            public function __construct(private readonly string $technicalName)
            {
            }

            public function getTechnicalName(): string
            {
                return $this->technicalName;
            }

            protected function buildProviderContext(
                ProductExportEntity $productExport,
                SalesChannelContext $salesChannelContext,
            ): array {
                return [];
            }
        };
    }
}
