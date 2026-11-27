<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/**
 * Reads the payment method the registered handler of an instrument resolves, without switching anything.
 * An empty or unavailable method is refused, never replaced by the sales channel default.
 *
 * @internal
 */
#[Package('checkout')]
final class RegisteredHandlerPaymentMethodResolver
{
    public function __construct(
        private readonly PaymentHandlerRegistryInterface $handlerRegistry,
        private readonly SalesChannelPaymentMethods $paymentMethods,
    ) {
    }

    /**
     * @return string the id of the payment method that the buyer of the context may use
     *
     * @throws CompletionPaymentException when no handler is registered for the instrument, or its payment method is not available
     * @throws ValidationException        when the payment handler refuses the instrument
     */
    public function resolve(?PaymentInstrument $instrument, SalesChannelContext $context, RequestContext $requestContext): string
    {
        $paymentHandler = null === $instrument ? null : $this->handlerRegistry->find($instrument->handlerId);
        if (null === $instrument || null === $paymentHandler) {
            throw CompletionPaymentException::handlerNotRegistered($instrument?->handlerId);
        }

        $paymentMethodId = self::paymentMethodId($paymentHandler->prepareInstrument($instrument, $requestContext));
        if (null === $paymentMethodId || !$this->paymentMethods->isAvailable($paymentMethodId, $context)) {
            throw CompletionPaymentException::paymentMethodUnavailable($instrument->handlerId);
        }

        return $paymentMethodId;
    }

    /**
     * @param array<mixed> $preparedInstrument
     */
    private static function paymentMethodId(array $preparedInstrument): ?string
    {
        $paymentMethodId = $preparedInstrument['paymentMethodId'] ?? null;

        return \is_string($paymentMethodId) && Uuid::isValid($paymentMethodId) ? $paymentMethodId : null;
    }
}
