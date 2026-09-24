<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/**
 * Resolves a UCP payment instrument to the concrete Shopware payment method a
 * provider can charge, without deciding who charges or how.
 *
 * This is the "intended path" docs/completion-payment.md names but which every
 * earlier release left unimplemented: the SDK's
 * PaymentHandlerInterface::prepareInstrument() existed and
 * ShopwareInvoicePaymentHandler implemented it, yet nothing ever called it. A
 * payment integration had to reimplement handler lookup + prepareInstrument
 * for itself -- or skip it and fall back to the sales channel default, which is
 * how an agent's instrument used to disappear without trace.
 *
 * It is deliberately rail- and provider-agnostic: it only turns the instrument
 * into the pair (paymentMethodId, token) the handler declares. Deciding whether
 * to switch the order to that method, refusing, or settling on-chain stays with
 * the caller's applier, exactly as the seam intends.
 *
 * @internal
 */
#[Package('checkout')]
final class PaymentInstrumentResolver
{
    public function __construct(
        private readonly PaymentHandlerRegistryInterface $paymentHandlerRegistry,
    ) {
    }

    /**
     * @return array{paymentMethodId: string, token: string, displayLast4?: string, displayBrand?: string}
     */
    public function prepare(
        PaymentInstrument $instrument,
        RequestContext $context,
    ): array {
        $handler = $this->paymentHandlerRegistry->find($instrument->handlerId);
        if (null === $handler) {
            throw new ValidationException(\sprintf(
                'No UCP payment handler is registered for id "%s". The business must publish a payment handler for it before an agent can settle a completion with it.',
                $instrument->handlerId,
            ));
        }

        return $handler->prepareInstrument($instrument, $context);
    }
}
