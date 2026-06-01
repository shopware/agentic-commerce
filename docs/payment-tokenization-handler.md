# Payment Tokenization Handler Guide

`SwagAgenticCommerce` intentionally does not ship a fake payment tokenizer.
The bundled `ShopwareInvoicePaymentHandler` describes an offline invoice-style
payment flow and returns `supportsTokenization() === false`.

Real UCP payment tokenization must be implemented by a PSP/payment plugin that
can safely exchange payment credentials for a reusable PSP token.

## Runtime Contract

A sales channel advertises payment tokenization only when all conditions are
true:

- A service implementing `Ucp\Sdk\Contract\PaymentHandlerInterface` is
  registered with the `ucp_sdk.payment_handler` tag.
- That handler returns `true` from `supportsTokenization()`.
- The sales channel config enables the `payment_tokenization` capability.

Until then:

- `/.well-known/ucp` must expose an empty `payment_handlers` list.
- `/.well-known/ucp` must not advertise
  `dev.ucp.shopping.payment_tokenization`.
- `POST /ucp/v1/tokenize` must return a controlled `501`.

## Example PSP Handler

This is the expected shape for a real implementation. Keep the actual PSP
client, credential validation, and token vaulting inside the payment plugin.

```php
<?php

declare(strict_types=1);

namespace Acme\Payment\Ucp;

use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;

final readonly class AcmeCardTokenizationHandler implements PaymentHandlerInterface
{
    public function __construct(
        private AcmePspClient $pspClient,
    ) {
    }

    public function id(): string
    {
        return 'com.acme.card';
    }

    public function describe(RequestContext $context): PaymentHandlerDescriptor
    {
        return new PaymentHandlerDescriptor(
            $this->id(),
            'Acme card tokenization',
            '2026-04-08',
            'https://docs.acme.example/ucp/payment-handler',
            'https://ucp.dev/schemas/payments/delegate-payment.json',
            ['https://ucp.dev/schemas/shopping/types/card_payment_instrument.json'],
            [
                'tokenization' => true,
                'supported_brands' => ['visa', 'mastercard'],
            ],
        );
    }

    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
    {
        $token = (string) ($instrument->credential['token'] ?? '');

        return [
            'paymentMethodId' => $this->resolveShopwarePaymentMethodId($context),
            'token' => $token,
            'displayLast4' => (string) ($instrument->credential['last4'] ?? ''),
            'displayBrand' => (string) ($instrument->credential['brand'] ?? ''),
        ];
    }

    public function supportsTokenization(): bool
    {
        return true;
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
    {
        if ($instrument->type !== 'card') {
            return null;
        }

        $pspToken = $this->pspClient->tokenizeCard(
            salesChannelId: (string) $context->runtimeConfiguration?->tenantIdentifier,
            credential: $instrument->credential,
        );

        return [
            'handler_id' => $this->id(),
            'type' => 'card',
            'token' => $pspToken->token,
            'display_last4' => $pspToken->last4,
            'display_brand' => $pspToken->brand,
        ];
    }

    private function resolveShopwarePaymentMethodId(RequestContext $context): string
    {
        // Resolve this from the payment plugin's own sales-channel config.
        return 'replace-with-shopware-payment-method-id';
    }
}
```

Register the handler in the PSP plugin:

```xml
<service id="Acme\Payment\Ucp\AcmeCardTokenizationHandler">
    <tag name="ucp_sdk.payment_handler"/>
</service>
```

## Validation Checklist

After installing the PSP plugin:

1. Enable `payment_tokenization` for the target sales channel in UCP admin.
2. Fetch `/.well-known/ucp` and verify:
   - `dev.ucp.shopping.payment_tokenization` is present in `capabilities`.
   - `payment_handlers` contains the PSP handler id.
3. Call `POST /ucp/v1/tokenize` with the PSP handler id and a valid test
   credential.
4. Verify checkout can use the returned token without exposing raw credentials
   to Shopware core or this plugin.

## Non-Goals

- Do not tokenize raw payment data in `SwagAgenticCommerce`.
- Do not return placeholder tokens just to make the profile look complete.
- Do not advertise tokenization for offline payment methods such as invoice.
- Do not enable `payment_tokenization` unless at least one handler returns
  `supportsTokenization() === true`.
