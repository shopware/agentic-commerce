<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Payment;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * Stands for one of Shopware's offline payment methods. The agent names the handler, and the payment
 * method of the sales channel that runs the core handler class is resolved on the server.
 *
 * @internal
 */
#[Package('checkout')]
final class ShopwareOfflinePaymentHandler implements PaymentHandlerInterface
{
    public const INVOICE_ID = 'com.shopware.invoice';
    public const PREPAYMENT_ID = 'com.shopware.prepayment';
    public const CASH_ON_DELIVERY_ID = 'com.shopware.cash_on_delivery';

    private const ID_PREFIX = 'com.shopware.';

    /**
     * @param string $coreHandlerClass the `handlerIdentifier` of the payment method this handler stands for
     */
    public function __construct(
        private readonly string $id,
        private readonly string $coreHandlerClass,
        private readonly SalesChannelPaymentMethods $paymentMethods,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function coreHandlerClass(): string
    {
        return $this->coreHandlerClass;
    }

    public function describe(RequestContext $context): PaymentHandlerDescriptor
    {
        $name = substr($this->id, \strlen(self::ID_PREFIX));

        return new PaymentHandlerDescriptor(
            id: $this->id,
            name: $this->id,
            version: UcpProtocol::VERSION,
            specUrl: 'https://developer.shopware.com/ucp/payment-handlers/'.str_replace('_', '-', $name),
            configSchema: 'https://ucp.dev/schemas/payments/delegate-payment.json',
            instrumentSchemas: ['https://ucp.dev/schemas/shopping/types/'.$name.'_payment_instrument.json'],
            config: [
                'tokenization' => false,
                'description' => 'Uses the payment method of the sales channel that runs the matching Shopware offline payment handler. No raw credential tokenization is performed.',
            ],
        );
    }

    /**
     * Ignores the credential of the instrument: the agent does not choose the payment method.
     */
    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
    {
        $salesChannelId = $context->runtimeConfiguration?->tenantIdentifier;

        return [
            'paymentMethodId' => null === $salesChannelId ? '' : $this->paymentMethodId($salesChannelId) ?? '',
            'token' => '',
        ];
    }

    public function paymentMethodId(string $salesChannelId): ?string
    {
        return $this->paymentMethods->idForHandler($this->coreHandlerClass, $salesChannelId);
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
