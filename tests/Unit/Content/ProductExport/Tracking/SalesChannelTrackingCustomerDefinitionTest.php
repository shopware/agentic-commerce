<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Tracking;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\EntityWriteGateway;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingCustomerCollection;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingCustomerDefinition;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingCustomerEntity;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(SalesChannelTrackingCustomerDefinition::class)]
class SalesChannelTrackingCustomerDefinitionTest extends TestCase
{
    private SalesChannelTrackingCustomerDefinition $definition;

    protected function setUp(): void
    {
        $registry = new StaticDefinitionInstanceRegistry(
            [SalesChannelTrackingCustomerDefinition::class],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $definition = $registry->getByEntityName(SalesChannelTrackingCustomerDefinition::ENTITY_NAME);
        static::assertInstanceOf(SalesChannelTrackingCustomerDefinition::class, $definition);
        $this->definition = $definition;
    }

    public function testEntityName(): void
    {
        static::assertSame('sales_channel_tracking_customer', $this->definition->getEntityName());
    }

    public function testEntityClass(): void
    {
        static::assertSame(SalesChannelTrackingCustomerEntity::class, $this->definition->getEntityClass());
    }

    public function testCollectionClass(): void
    {
        static::assertSame(SalesChannelTrackingCustomerCollection::class, $this->definition->getCollectionClass());
    }

    public function testIdFieldIsPrimaryKey(): void
    {
        $field = $this->definition->getFields()->get('id');
        static::assertInstanceOf(IdField::class, $field);
        static::assertTrue($field->is(PrimaryKey::class));
        static::assertTrue($field->is(Required::class));
    }

    public function testCustomerIdField(): void
    {
        $field = $this->definition->getFields()->get('customerId');
        static::assertInstanceOf(FkField::class, $field);
        static::assertTrue($field->is(Required::class));
    }

    public function testSalesChannelIdField(): void
    {
        $field = $this->definition->getFields()->get('salesChannelId');
        static::assertInstanceOf(FkField::class, $field);
        static::assertTrue($field->is(Required::class));
    }

    public function testCustomerAssociation(): void
    {
        $field = $this->definition->getFields()->get('customer');
        static::assertInstanceOf(OneToOneAssociationField::class, $field);
    }

    public function testSalesChannelAssociation(): void
    {
        $field = $this->definition->getFields()->get('salesChannel');
        static::assertInstanceOf(ManyToOneAssociationField::class, $field);
    }
}
