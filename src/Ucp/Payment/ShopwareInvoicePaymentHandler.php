<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Payment;

use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Swag\AgenticCommerce\Ucp\UcpProtocol;

/** @internal */
final class ShopwareInvoicePaymentHandler implements PaymentHandlerInterface
{
    public function id(): string
    {
        return 'com.shopware.invoice';
    }

    public function describe(RequestContext $context): PaymentHandlerDescriptor
    {
        return new PaymentHandlerDescriptor(
            id: $this->id(),
            name: $this->id(),
            version: UcpProtocol::VERSION,
            specUrl: 'https://developer.shopware.com/ucp/payment-handlers/invoice',
            configSchema: 'https://ucp.dev/schemas/payments/delegate-payment.json',
            instrumentSchemas: ['https://ucp.dev/schemas/shopping/types/invoice_payment_instrument.json'],
            config: [
                'tokenization' => false,
                'description' => 'Uses the sales channel default invoice/offline payment flow. No raw credential tokenization is performed.',
            ],
        );
    }

    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
    {
        return [
            'paymentMethodId' => (string) ($instrument->credential['payment_method_id'] ?? ''),
            'token' => '',
        ];
    }

    public function supportsTokenization(): bool
    {
        return false;
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
    {
        return null;
    }
}
