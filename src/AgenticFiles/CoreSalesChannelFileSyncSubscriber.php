<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[Package('discovery')]
final class CoreSalesChannelFileSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AgenticFilesCoreBridgeInterface $bridge)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['syncOnAgenticFileRequest', 512],
        ];
    }

    public function syncOnAgenticFileRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = ltrim($event->getRequest()->getPathInfo(), '/');
        if (!\in_array($path, ['llms.txt', 'agents.md'], true)) {
            return;
        }

        $this->bridge->syncActiveUcpSalesChannels();
    }
}
