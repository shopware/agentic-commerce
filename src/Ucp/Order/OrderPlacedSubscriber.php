<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Order;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompleter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManagerInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Common\Buyer;

/**
 * Records a UCP checkout as completed when the buyer places the order, for example in the
 * storefront after following `continue_url`. Agent-driven completion is covered by
 * {@see CheckoutCompleter}; an order placed anywhere else never reached the completion store,
 * so the checkout read `incomplete` forever and no order webhook ever fired.
 *
 * @internal
 */
#[Package('checkout')]
final class OrderPlacedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CheckoutSessionStore $sessionStore,
        private readonly CheckoutSessionManagerInterface $sessionManager,
        private readonly CheckoutCompletionStoreInterface $completionStore,
        private readonly OrderWebhookEmitter $webhookEmitter,
        private readonly LockFactory $lockFactory,
        private readonly RequestStack $requestStack,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckoutOrderPlacedEvent::class => 'onOrderPlaced'];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        try {
            $this->recordCompletion($event);
        } catch (\Throwable $exception) {
            // The buyer's order is already placed; failing their request would not undo it.
            $this->logger?->error('Recording the UCP checkout completion failed.', [
                'orderId' => $event->getOrderId(),
                'exception' => $exception,
            ]);
        }
    }

    private function recordCompletion(CheckoutOrderPlacedEvent $event): void
    {
        if ($event->getContext()->hasState(CheckoutCompleter::AGENT_COMPLETION_STATE)) {
            return;
        }

        $salesChannelContext = $this->resolveSalesChannelContext($event);
        if (null === $salesChannelContext) {
            return;
        }

        $metadata = $this->sessionStore->load($salesChannelContext->getToken(), $event->getSalesChannelId());
        if ([] === $metadata) {
            return;
        }

        // A completed session stays on the buyer's token after the first order; a later order
        // on that session is not a UCP completion.
        $checkoutId = $this->sessionStore->checkoutId($metadata);
        $status = $metadata['status'] ?? null;
        if (null === $checkoutId
            || CheckoutStatus::Completed->value === $status
            || CheckoutStatus::Canceled->value === $status) {
            return;
        }

        // The completer's lock key: held there for the whole agent-driven completion, so an
        // in-flight `checkout.complete` records its own order and this one backs off.
        $lock = $this->lockFactory->createLock(
            'ucp.checkout.completion.'.$checkoutId.'.'.$event->getSalesChannelId(),
            300.0,
        );

        if (!$lock->acquire(false)) {
            return;
        }

        $order = $event->getOrder();

        try {
            if (null !== $this->completionStore->completedOrderId($checkoutId)) {
                return;
            }

            // Saved before the completion row: a reader that finds the row loads the session
            // afterwards and needs its rotated context token.
            $this->sessionManager->saveForCheckoutId(
                $checkoutId,
                $salesChannelContext,
                CheckoutStatus::Completed->value,
                $this->buyer($order, $metadata),
                orderId: $order->getId(),
                orderDeepLinkCode: $order->getDeepLinkCode(),
            );

            $this->completionStore->complete($checkoutId, $order->getId());
        } finally {
            $lock->release();
        }

        // Published after the lock: delivery may retry for seconds and needs no exclusivity.
        $this->webhookEmitter->publish('order.created', $order, $checkoutId);
    }

    /**
     * The event carries the SalesChannelContext only from 6.6.10 on, where the getter throws for
     * events constructed without one; before that, the ordering request's attribute is the one
     * source. Reflection on purpose: a direct call fails static analysis against 6.5/6.6 cores,
     * and a `method_exists` probe fails it against newer ones, which know the outcome.
     */
    private function resolveSalesChannelContext(CheckoutOrderPlacedEvent $event): ?SalesChannelContext
    {
        $reflection = new \ReflectionClass($event);
        if ($reflection->hasMethod('getSalesChannelContext')) {
            try {
                $context = $reflection->getMethod('getSalesChannelContext')->invoke($event);
                if ($context instanceof SalesChannelContext) {
                    return $context;
                }
            } catch (\Throwable) {
                // Fall through to the request attribute.
            }
        }

        $context = $this->requestStack->getMainRequest()?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        return $context instanceof SalesChannelContext ? $context : null;
    }

    /**
     * The buyer as the order states them: in this flow the buyer typed their own data into the
     * shop, so the agent-supplied session buyer is only the fallback.
     *
     * @param array<string, mixed> $metadata
     */
    private function buyer(OrderEntity $order, array $metadata): ?Buyer
    {
        $orderCustomer = $order->getOrderCustomer();
        if (null === $orderCustomer) {
            return $this->sessionStore->buyer($metadata);
        }

        return new Buyer(
            email: $orderCustomer->getEmail(),
            firstName: $orderCustomer->getFirstName(),
            lastName: $orderCustomer->getLastName(),
        );
    }
}
