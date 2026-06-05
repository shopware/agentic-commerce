<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelType\SalesChannelTypeDefinition;
use Shopware\Core\System\SalesChannel\Exception\DefaultSalesChannelTypeCannotBeDeleted;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\SwagAgenticCommerce;
use Swag\AgenticCommerce\System\SalesChannel\Subscriber\AgenticCommerceSalesChannelTypeProtectionSubscriber;

/**
 * @internal
 */
#[CoversClass(AgenticCommerceSalesChannelTypeProtectionSubscriber::class)]
class AgenticCommerceSalesChannelTypeProtectionSubscriberTest extends TestCase
{
    public function testSubscribesToPreWriteValidationEvent(): void
    {
        static::assertSame(
            [PreWriteValidationEvent::class => 'preWriteValidateEvent'],
            AgenticCommerceSalesChannelTypeProtectionSubscriber::getSubscribedEvents()
        );
    }

    public function testBlocksDeletionOfAgenticCommerceSalesChannelType(): void
    {
        $writeContext = WriteContext::createFromContext(Context::createDefaultContext());
        $event = new PreWriteValidationEvent($writeContext, [
            $this->createDeleteCommand(SalesChannelTypeDefinition::ENTITY_NAME, SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE),
        ]);

        $this->createSubscriber()->preWriteValidateEvent($event);

        $exceptions = $event->getExceptions()->getExceptions();
        static::assertCount(1, $exceptions);
        static::assertInstanceOf(DefaultSalesChannelTypeCannotBeDeleted::class, $exceptions[0]);
    }

    public function testIgnoresDeletionOfOtherSalesChannelTypes(): void
    {
        $writeContext = WriteContext::createFromContext(Context::createDefaultContext());
        $event = new PreWriteValidationEvent($writeContext, [
            $this->createDeleteCommand(SalesChannelTypeDefinition::ENTITY_NAME, Defaults::SALES_CHANNEL_TYPE_STOREFRONT),
            $this->createDeleteCommand(SalesChannelTypeDefinition::ENTITY_NAME, Uuid::randomHex()),
        ]);

        $this->createSubscriber()->preWriteValidateEvent($event);

        static::assertSame([], $event->getExceptions()->getExceptions());
    }

    public function testIgnoresInsertCommandsForAgenticCommerceSalesChannelType(): void
    {
        $writeContext = WriteContext::createFromContext(Context::createDefaultContext());
        $insertCommand = $this->createMock(InsertCommand::class);
        $insertCommand->method('getEntityName')->willReturn(SalesChannelTypeDefinition::ENTITY_NAME);
        $insertCommand->method('getPrimaryKey')->willReturn([
            'id' => Uuid::fromHexToBytes(SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE),
        ]);

        $event = new PreWriteValidationEvent($writeContext, [$insertCommand]);

        $this->createSubscriber()->preWriteValidateEvent($event);

        static::assertSame([], $event->getExceptions()->getExceptions());
    }

    public function testIgnoresDeletionOfUnrelatedEntities(): void
    {
        $writeContext = WriteContext::createFromContext(Context::createDefaultContext());
        $event = new PreWriteValidationEvent($writeContext, [
            $this->createDeleteCommand('product', SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE),
        ]);

        $this->createSubscriber()->preWriteValidateEvent($event);

        static::assertSame([], $event->getExceptions()->getExceptions());
    }

    private function createSubscriber(): AgenticCommerceSalesChannelTypeProtectionSubscriber
    {
        return new AgenticCommerceSalesChannelTypeProtectionSubscriber(new ShopwareVersionDetector());
    }

    private function createDeleteCommand(string $entityName, string $hexId): DeleteCommand
    {
        $command = $this->createMock(DeleteCommand::class);
        $command->method('getEntityName')->willReturn($entityName);
        $command->method('getPrimaryKey')->willReturn(['id' => Uuid::fromHexToBytes($hexId)]);

        return $command;
    }
}
