<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\HttpException;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
#[Package('checkout')]
final class CompletionPaymentException extends HttpException
{
    public const PAYMENT_METHOD_UNAVAILABLE = 'SWAG_AGENTIC_COMMERCE__UCP_PAYMENT_METHOD_UNAVAILABLE';
    public const HANDLER_NOT_REGISTERED = 'SWAG_AGENTIC_COMMERCE__UCP_PAYMENT_HANDLER_NOT_REGISTERED';
    public const PAYMENT_FAILED = 'SWAG_AGENTIC_COMMERCE__UCP_PAYMENT_FAILED';
    public const PAYMENT_METHOD_CHANGES_TOTAL = 'SWAG_AGENTIC_COMMERCE__UCP_PAYMENT_METHOD_CHANGES_TOTAL';
    public const PAYMENT_METHOD_NOT_CHANGEABLE = 'SWAG_AGENTIC_COMMERCE__UCP_PAYMENT_METHOD_NOT_CHANGEABLE';
    public const ORDER_CANNOT_BE_PAID_AGAIN = 'SWAG_AGENTIC_COMMERCE__UCP_ORDER_CANNOT_BE_PAID_AGAIN';

    public static function paymentMethodUnavailable(string $handlerId): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::PAYMENT_METHOD_UNAVAILABLE,
            'The payment handler "{{ handlerId }}" has no payment method that is available to this checkout.',
            ['handlerId' => $handlerId],
        );
    }

    public static function handlerNotRegistered(?string $handlerId): self
    {
        if (null === $handlerId) {
            return new self(
                Response::HTTP_BAD_REQUEST,
                self::HANDLER_NOT_REGISTERED,
                'The checkout carries no payment instrument, so no payment handler applies.',
            );
        }

        return new self(
            Response::HTTP_BAD_REQUEST,
            self::HANDLER_NOT_REGISTERED,
            'No payment handler "{{ handlerId }}" is registered.',
            ['handlerId' => $handlerId],
        );
    }

    public static function paymentFailed(string $errorCode): self
    {
        return new self(
            Response::HTTP_BAD_GATEWAY,
            self::PAYMENT_FAILED,
            'Shopware answered the payment with its error redirect, error code "{{ errorCode }}".',
            ['errorCode' => $errorCode],
        );
    }

    public static function paymentMethodNotChangeable(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::PAYMENT_METHOD_NOT_CHANGEABLE,
            'This payment method cannot pay an order that is already placed.',
        );
    }

    public static function orderCannotBePaidAgain(): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::ORDER_CANNOT_BE_PAID_AGAIN,
            'The order is placed, and no payment method this business advertises can pay an order that is already placed. The order is kept, and the shop has to settle it with the buyer.',
        );
    }

    public static function paymentMethodChangesTotal(): self
    {
        return new self(
            Response::HTTP_CONFLICT,
            self::PAYMENT_METHOD_CHANGES_TOTAL,
            'Selecting this payment instrument changes the total of the checkout. Update the checkout with this instrument first, then complete it.',
        );
    }
}
