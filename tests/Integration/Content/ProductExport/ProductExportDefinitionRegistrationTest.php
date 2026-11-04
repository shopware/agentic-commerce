<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\Content\ProductExport;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductExport\ProductExportDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelDefinitionInstanceRegistry;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;

/**
 * The plugin's product export definition must not be registered a second time under its own class
 * name.
 *
 * @internal
 */
final class ProductExportDefinitionRegistrationTest extends TestCase
{
    public function testDefinitionRegistryResolvesProductExportByClassAndEntityNameToTheSameDefinition(): void
    {
        $registry = $this->getService(DefinitionInstanceRegistry::class);

        static::assertSame(
            $registry->get(ProductExportDefinition::class),
            $registry->getByEntityName('product_export')
        );
    }

    public function testSalesChannelRegistryResolvesProductExportByClassAndEntityNameToTheSameDefinition(): void
    {
        $registry = $this->getService(SalesChannelDefinitionInstanceRegistry::class);

        static::assertSame(
            $registry->get(ProductExportDefinition::class),
            $registry->getByEntityName('product_export')
        );
    }

    public function testSalesChannelRegistryResolvesTheSalesChannelProductExportsAssociation(): void
    {
        $registry = $this->getService(SalesChannelDefinitionInstanceRegistry::class);

        $association = $registry->get(SalesChannelDefinition::class)->getFields()->get('productExports');
        static::assertInstanceOf(OneToManyAssociationField::class, $association);

        static::assertSame('product_export', $association->getReferenceDefinition()->getEntityName());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function getService(string $id): object
    {
        $service = KernelLifecycleManager::getKernel()->getContainer()->get($id);
        static::assertInstanceOf($id, $service);

        return $service;
    }
}
