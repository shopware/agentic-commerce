<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Swag\AgenticCommerce\Ucp\Payment\ShopwareOfflinePaymentHandler;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/**
 * Refuses a payment method without "Allow payment change after checkout" for a placed order, as far as core does.
 *
 * @internal
 */
#[Package('checkout')]
final class PlacedOrderPaymentMethodGate
{
    public function __construct(
        private readonly ShopwareVersionDetector $versionDetector,
        private readonly AdvertisedPaymentHandlers $advertisedHandlers,
        private readonly PaymentHandlerRegistryInterface $handlerRegistry,
        private readonly SalesChannelPaymentMethods $paymentMethods,
    ) {
    }

    /**
     * @throws CompletionPaymentException
     * @throws UcpConfigException
     */
    public function assertAllowed(PaymentMethodEntity $paymentMethod, string $salesChannelId, RequestContext $requestContext): void
    {
        if (!$this->versionDetector->enforcesPaymentChangeAfterOrder() || $paymentMethod->getAfterOrderEnabled()) {
            return;
        }

        if ($this->canBePaidByAdvertisedHandler($salesChannelId, $requestContext)) {
            throw CompletionPaymentException::paymentMethodNotChangeable();
        }

        throw CompletionPaymentException::orderCannotBePaidAgain();
    }

    /**
     * @throws UcpConfigException
     */
    public function canBePaidByAdvertisedHandler(string $salesChannelId, RequestContext $requestContext): bool
    {
        if (!$this->versionDetector->enforcesPaymentChangeAfterOrder()) {
            return true;
        }

        $advertisedHandlers = [];
        foreach ($this->advertisedHandlers->ids($requestContext) as $handlerId) {
            $handler = $this->handlerRegistry->find($handlerId);
            if (null !== $handler) {
                $advertisedHandlers[] = $handler;
            }
        }

        if ([] === $advertisedHandlers) {
            return false;
        }

        $afterOrderEnabledFlagsByHandlerClass = $this->paymentMethods->afterOrderEnabledFlagsByHandlerClass($salesChannelId);
        $canPaymentExtensionPay = $this->hasPaymentExtensionMethodWithAfterOrderEnabled($afterOrderEnabledFlagsByHandlerClass);
        foreach ($advertisedHandlers as $handler) {
            $canHandlerPay = $handler instanceof ShopwareOfflinePaymentHandler
                ? true === ($afterOrderEnabledFlagsByHandlerClass[$handler->coreHandlerClass()][0] ?? false)
                : $canPaymentExtensionPay;

            if ($canHandlerPay) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, list<bool>> $afterOrderEnabledFlagsByHandlerClass
     */
    private function hasPaymentExtensionMethodWithAfterOrderEnabled(array $afterOrderEnabledFlagsByHandlerClass): bool
    {
        $offlineHandlerClasses = [];
        foreach ($this->handlerRegistry->all() as $handler) {
            if ($handler instanceof ShopwareOfflinePaymentHandler) {
                $offlineHandlerClasses[] = $handler->coreHandlerClass();
            }
        }

        foreach ($afterOrderEnabledFlagsByHandlerClass as $handlerClass => $afterOrderEnabledFlags) {
            if (!\in_array($handlerClass, $offlineHandlerClasses, true) && \in_array(true, $afterOrderEnabledFlags, true)) {
                return true;
            }
        }

        return false;
    }
}
