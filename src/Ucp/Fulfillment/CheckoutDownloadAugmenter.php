<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Fulfillment;

use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Ucp\Sdk\Contract\CheckoutResponseAugmenterInterface;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\RequestContext;

/**
 * Adds `extra.downloads` to a UCP checkout so an agent can fetch purchased
 * digital files via the deepLinkCode-authorized download route
 * (AgenticOrderDownloadRoute). The links are present from completion but only
 * work once the order is paid (the route enforces accessGranted). Mirrors the
 * x402 augmenter's shape (base URL resolved from the Host header).
 */
class CheckoutDownloadAugmenter implements CheckoutResponseAugmenterInterface
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(private readonly EntityRepository $orderRepository)
    {
    }

    public function augment(Checkout $checkout, RequestContext $context): Checkout
    {
        if (null === $checkout->order) {
            return $checkout;
        }

        $order = $this->loadOrder($checkout->order->id);
        $deepLinkCode = $order?->getDeepLinkCode();
        if (null === $order || null === $deepLinkCode) {
            return $checkout;
        }

        $baseUri = $this->resolveBaseUri($context);
        if ('' === $baseUri) {
            return $checkout;
        }

        $downloads = [];
        foreach ($order->getLineItems() ?? [] as $lineItem) {
            foreach ($lineItem->getDownloads() ?? [] as $download) {
                $media = $download->getMedia();
                $extension = $media?->getFileExtension();
                $downloads[] = [
                    'download_id' => $download->getId(),
                    'filename' => ($media?->getFileName() ?? 'download').(null !== $extension && '' !== $extension ? '.'.$extension : ''),
                    'url' => \sprintf(
                        '%s/store-api/agentic-commerce/order/%s/download/%s?deepLinkCode=%s',
                        $baseUri,
                        $order->getId(),
                        $download->getId(),
                        rawurlencode($deepLinkCode),
                    ),
                    'available' => $download->isAccessGranted(),
                ];
            }
        }

        if ([] === $downloads) {
            return $checkout;
        }

        $extra = array_merge($checkout->extra, ['downloads' => $downloads]);
        $accessKey = $order->getSalesChannel()?->getAccessKey();
        if (null !== $accessKey) {
            $extra['downloads_access_key'] = $accessKey;
        }

        return new Checkout(
            $checkout->id,
            $checkout->status,
            $checkout->currency,
            $checkout->lineItems,
            $checkout->totals,
            $checkout->messages,
            $checkout->links,
            $checkout->buyer,
            $checkout->continueUrl,
            $checkout->expiresAt,
            $checkout->order,
            $extra,
        );
    }

    private function loadOrder(string $orderId): ?OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('lineItems.downloads.media');
        $criteria->addAssociation('salesChannel');

        $order = $this->orderRepository->search($criteria, Context::createDefaultContext())->getEntities()->first();

        return $order instanceof OrderEntity ? $order : null;
    }

    private function resolveBaseUri(RequestContext $context): string
    {
        $configured = rtrim($context->runtimeConfiguration?->baseUri ?? '', '/');
        if ('' !== $configured) {
            return $configured;
        }

        $host = $context->headers['host'] ?? $context->host;
        if ('' === $host) {
            return '';
        }

        $isLocal = str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');

        return ($isLocal ? 'http' : 'https').'://'.$host;
    }
}
