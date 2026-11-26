<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Payment\ShopwareInvoicePaymentHandler;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * The default: change nothing, and say so.
 *
 * {@see CompletionPaymentGate} has already refused every completion whose single instrument names
 * a handler the profile does not advertise, so this only sees an advertised handler. For one
 * that has no applier yet, the order is placed with the sales channel default and the warning
 * names the handler the agent asked for.
 *
 * Deliberately a warning rather than a refusal. The refusal for a credential or a payment method
 * the provider cannot honour belongs to the real applier, and the base class says so.
 *
 * Replace it by aliasing {@see AbstractCompletionPaymentApplier} to your own service, or
 * decorate this one and delegate through `getDecorated()` for the instruments you do not
 * handle. This is the end of the chain, so its own `getDecorated()` throws.
 *
 * @internal
 */
#[Package('framework')]
final class UnappliedCompletionPayment extends AbstractCompletionPaymentApplier
{
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function getDecorated(): AbstractCompletionPaymentApplier
    {
        throw new DecorationPatternException(self::class);
    }

    public function apply(
        ?PaymentInstrument $instrument,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): SalesChannelContext {
        // The plugin's own handler stands for the sales channel default, so there is nothing to warn about.
        if (null === $instrument || ShopwareInvoicePaymentHandler::ID === $instrument->handlerId) {
            return $context;
        }

        $this->logger?->warning(
            'A UCP checkout was completed with the sales channel default payment method, ignoring the instrument the agent presented. '
            .'Register a service under '.AbstractCompletionPaymentApplier::class.' to act on it; see docs/completion-payment.md.',
            [
                'handler_id' => $instrument->handlerId,
                'instrument_type' => $instrument->type,
                'sales_channel_id' => $context->getSalesChannelId(),
            ],
        );

        return $context;
    }
}
