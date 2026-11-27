<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Order;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutWebhookUrlGuard;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapperInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

/**
 * Publishes an order webhook for a UCP checkout outside the UCP request cycle, where no request
 * context exists; it is rebuilt from the sales channel's runtime configuration.
 *
 * @internal
 */
#[Package('after-sales')]
final class OrderWebhookEmitter
{
    public function __construct(
        private readonly UcpConfigService $configService,
        private readonly CheckoutWebhookUrlGuard $webhookUrlGuard,
        private readonly OrderPermalinkBuilder $orderPermalinkBuilder,
        private readonly ShopwareDataMapperInterface $mapper,
        private readonly OrderWebhookPublisherInterface $webhookPublisher,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function publish(string $event, OrderEntity $order, string $checkoutId): void
    {
        $salesChannelId = $order->getSalesChannelId();
        $config = $this->configService->getConfig($salesChannelId);
        if (null === $config->webhookUrlOverride) {
            return;
        }

        try {
            $this->webhookUrlGuard->assertAllowed($config->webhookUrlOverride, $config, $salesChannelId);
        } catch (ValidationException $exception) {
            $this->logger?->warning('Order webhook not sent: the webhook URL is not permitted.', [
                'event' => $event,
                'orderId' => $order->getId(),
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        $runtimeConfiguration = $config->toRuntimeConfiguration('', $salesChannelId);
        $host = parse_url($runtimeConfiguration->baseUri, \PHP_URL_HOST) ?: '';
        // The same order URL the completion and the read return: `permalink_url` means one
        // thing, and a continue URL is a checkout return address rather than an order page.
        $requestContext = new RequestContext($host, runtimeConfiguration: $runtimeConfiguration);
        $permalinkUrl = $this->orderPermalinkBuilder->build($order, $requestContext);

        $this->webhookPublisher->publish(
            $config->webhookUrlOverride,
            new OrderWebhookPayload(event: $event, orderId: $order->getId(), payload: [
                'order' => $this->mapper->toOrderView($order, $permalinkUrl, $checkoutId)->toArray(),
            ]),
            $requestContext,
        );
    }
}
