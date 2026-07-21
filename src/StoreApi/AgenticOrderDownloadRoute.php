<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\StoreApi;

use Shopware\Core\Checkout\Order\Aggregate\OrderLineItemDownload\OrderLineItemDownloadCollection;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Content\Media\File\DownloadResponseGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Agent-native digital delivery: lets a UCP guest agent download a purchased
 * file using the order's deepLinkCode as ownership proof (mirrors the x402
 * ownership model), instead of a customer session. Streams the same media the
 * core /store-api/order/download route serves, but gated by deepLinkCode +
 * accessGranted (so it 403s until the order is paid).
 */
#[Route(defaults: ['_routeScope' => ['store-api']])]
class AgenticOrderDownloadRoute
{
    /**
     * @param EntityRepository<OrderCollection>                 $orderRepository
     * @param EntityRepository<OrderLineItemDownloadCollection> $downloadRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $downloadRepository,
        private readonly DownloadResponseGenerator $downloadResponseGenerator,
    ) {
    }

    #[Route(
        path: '/store-api/agentic-commerce/order/{orderId}/download/{downloadId}',
        name: 'store-api.agentic-commerce.order.download',
        methods: ['GET']
    )]
    public function download(string $orderId, string $downloadId, Request $request, SalesChannelContext $context): Response
    {
        $deepLinkCode = (string) $request->query->get('deepLinkCode', '');

        $order = $this->orderRepository->search(new Criteria([$orderId]), $context->getContext())->getEntities()->first();
        if (null === $order) {
            throw new NotFoundHttpException('Order not found.');
        }

        $orderDeepLinkCode = $order->getDeepLinkCode();
        if ('' === $deepLinkCode || null === $orderDeepLinkCode || !hash_equals($orderDeepLinkCode, $deepLinkCode)) {
            throw new AccessDeniedHttpException('Invalid or missing deepLinkCode for this order.');
        }

        $criteria = new Criteria([$downloadId]);
        $criteria->addAssociation('media');
        $criteria->addFilter(new EqualsFilter('orderLineItem.orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('accessGranted', true));

        $download = $this->downloadRepository->search($criteria, $context->getContext())->getEntities()->first();
        if (null === $download || null === $download->getMedia()) {
            throw new AccessDeniedHttpException('Download not available (not found, not paid, or access not granted).');
        }

        return $this->downloadResponseGenerator->getResponse($download->getMedia(), $context);
    }
}
