<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Gateway;

use Shopware\Core\Checkout\Order\SalesChannel\AbstractSetPaymentOrderRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractHandlePaymentMethodRoute;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AbstractCompletionPaymentApplier;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentException;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;

/** @internal */
#[Package('checkout')]
final class ShopwareOrderPaymentGateway implements OrderPaymentGatewayInterface
{
    public function __construct(
        private readonly AbstractHandlePaymentMethodRoute $handlePaymentRoute,
        private readonly AbstractSetPaymentOrderRoute $setPaymentOrderRoute,
    ) {
    }

    public function pay(string $orderId, SalesChannelContext $context, ?PaymentInstrument $instrument, string $finishUrl, string $errorUrl): ?string
    {
        $redirectUrl = $this->handlePaymentRoute->load(
            new Request([], [
                'orderId' => $orderId,
                'finishUrl' => $finishUrl,
                'errorUrl' => $errorUrl,
                ...self::instrumentData($instrument),
            ]),
            $context,
        )->getRedirectResponse()?->getTargetUrl();

        // With an error URL, Shopware answers a failed payment with a redirect to it instead of throwing.
        if (null !== $redirectUrl && str_starts_with($redirectUrl, $errorUrl)) {
            parse_str((string) parse_url($redirectUrl, \PHP_URL_QUERY), $query);
            $errorCode = $query['error-code'] ?? null;

            // With one URL for both outcomes, only the error code tells a failure from a success.
            if ($finishUrl !== $errorUrl || \is_string($errorCode)) {
                throw CompletionPaymentException::paymentFailed(\is_string($errorCode) ? $errorCode : '');
            }
        }

        return $redirectUrl;
    }

    public function changePaymentMethod(string $orderId, SalesChannelContext $context): void
    {
        $this->setPaymentOrderRoute->setPayment(
            new Request([], [
                'orderId' => $orderId,
                'paymentMethodId' => $context->getPaymentMethod()->getId(),
            ]),
            $context,
        );
    }

    /**
     * @return array<string, array<string, mixed>> what a Shopware payment handler finds in the request, empty without an instrument
     */
    public static function instrumentData(?PaymentInstrument $instrument): array
    {
        if (null === $instrument) {
            return [];
        }

        return [AbstractCompletionPaymentApplier::REQUEST_KEY => [
            'handlerId' => $instrument->handlerId,
            'type' => $instrument->type,
            'credential' => $instrument->credential,
            'billingAddress' => $instrument->billingAddress,
        ]];
    }
}
