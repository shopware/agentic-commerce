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
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingOrderCollection;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingOrderDefinition;
use Swag\AgenticCommerce\Content\ProductExport\Tracking\SalesChannelTrackingOrderEntity;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(SalesChannelTrackingOrderDefinition::class)]
class SalesChannelTrackingOrderDefinitionTest extends TestCase
{
    private SalesChannelTrackingOrderDefinition $definition;

    protected function setUp(): void
    {
        $registry = new StaticDefinitionInstanceRegistry(
            [SalesChannelTrackingOrderDefinition::class],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGateway::class),
        );

        $definition = $registry->getByEntityName(SalesChannelTrackingOrderDefinition::ENTITY_NAME);
        static::assertInstanceOf(SalesChannelTrackingOrderDefinition::class, $definition);
        $this->definition = $definition;
    }

    public function testEntityName(): void
    {
        static::assertSame('sales_channel_tracking_order', $this->definition->getEntityName());
    }

    public function testEntityClass(): void
    {
        static::assertSame(SalesChannelTrackingOrderEntity::class, $this->definition->getEntityClass());
    }

    public function testCollectionClass(): void
    {
        static::assertSame(SalesChannelTrackingOrderCollection::class, $this->definition->getCollectionClass());
    }

    public function testIdFieldIsPrimaryKey(): void
    {
        $field = $this->definition->getFields()->get('id');
        static::assertInstanceOf(IdField::class, $field);
        static::assertTrue($field->is(PrimaryKey::class));
        static::assertTrue($field->is(Required::class));
    }

    public function testOrderIdField(): void
    {
        $field = $this->definition->getFields()->get('orderId');
        static::assertInstanceOf(FkField::class, $field);
        static::assertTrue($field->is(Required::class));
    }

    public function testOrderVersionIdField(): void
    {
        $field = $this->definition->getFields()->get('orderVersionId');
        static::assertInstanceOf(ReferenceVersionField::class, $field);
    }

    public function testSalesChannelIdField(): void
    {
        $field = $this->definition->getFields()->get('salesChannelId');
        static::assertInstanceOf(FkField::class, $field);
        static::assertTrue($field->is(Required::class));
    }

    public function testOrderAssociation(): void
    {
        $field = $this->definition->getFields()->get('order');
        static::assertInstanceOf(OneToOneAssociationField::class, $field);
    }

    public function testSalesChannelAssociation(): void
    {
        $field = $this->definition->getFields()->get('salesChannel');
        static::assertInstanceOf(ManyToOneAssociationField::class, $field);
    }
}
