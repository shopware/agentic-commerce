<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Subscriber;

use Shopware\Core\Content\ProductExport\Event\ProductExportRenderBodyContextEvent;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Content\ProductExport\Provider\AgenticCommerceProductExportProviderRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
class AgenticCommerceProductExportProviderContextSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AgenticCommerceProductExportProviderRegistry $providerRegistry,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductExportRenderBodyContextEvent::class => 'extendBodyContext',
        ];
    }

    public function extendBodyContext(ProductExportRenderBodyContextEvent $event): void
    {
        $context = $event->getContext();
        $productExport = $context['productExport'] ?? null;
        $salesChannelContext = $context['context'] ?? null;

        if (!$productExport instanceof ProductExportEntity || !$salesChannelContext instanceof SalesChannelContext) {
            return;
        }

        $providerKey = $productExport->has('provider') ? $productExport->get('provider') : null;

        if (!\is_string($providerKey) || '' === $providerKey) {
            $providerKey = $this->requestStack->getCurrentRequest()?->request->get('provider');
        }

        if (!\is_string($providerKey) || '' === $providerKey) {
            return;
        }

        $provider = $this->providerRegistry->getByTechnicalName($providerKey);

        if (null === $provider) {
            return;
        }

        $event->setContext($provider->extendRenderContext(
            $productExport,
            $salesChannelContext,
            $context,
        ));
    }
}
