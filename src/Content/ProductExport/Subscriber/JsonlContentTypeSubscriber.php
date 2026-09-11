<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Subscriber;

use Shopware\Core\Content\ProductExport\Event\ProductExportContentTypeEvent;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\SwagAgenticCommerce;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal
 */
#[Package('discovery')]
class JsonlContentTypeSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductExportContentTypeEvent::class => 'onContentType',
        ];
    }

    public function onContentType(ProductExportContentTypeEvent $event): void
    {
        if (SwagAgenticCommerce::FILE_FORMAT_JSONL === $event->getFileFormat()) {
            $event->setContentType('application/x-ndjson');
        }
    }
}
