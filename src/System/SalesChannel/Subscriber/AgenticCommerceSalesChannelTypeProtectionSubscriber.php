<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\System\SalesChannel\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelType\SalesChannelTypeDefinition;
use Shopware\Core\System\SalesChannel\Exception\DefaultSalesChannelTypeCannotBeDeleted;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\SwagAgenticCommerce;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Prevents deletion of the Agentic Commerce sales channel type, matching the
 * behaviour that Shopware 6.7.10+ adds to
 * {@see \Shopware\Core\System\SalesChannel\Subscriber\SalesChannelTypeValidator}.
 *
 * @internal
 */
class AgenticCommerceSalesChannelTypeProtectionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ShopwareVersionDetector $versionDetector)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PreWriteValidationEvent::class => 'preWriteValidateEvent',
        ];
    }

    public function preWriteValidateEvent(PreWriteValidationEvent $event): void
    {
        if ($this->versionDetector->coreShipsAgenticCommerce()) {
            return;
        }

        foreach ($event->getCommands() as $command) {
            if (!$command instanceof DeleteCommand || SalesChannelTypeDefinition::ENTITY_NAME !== $command->getEntityName()) {
                continue;
            }

            $id = Uuid::fromBytesToHex($command->getPrimaryKey()['id']);

            if (SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE === $id) {
                $event->getExceptions()->add(new DefaultSalesChannelTypeCannotBeDeleted($id));
            }
        }
    }
}
