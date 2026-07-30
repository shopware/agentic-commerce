<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Test\Ap2;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizationResult;
use Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizerInterface;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * Deterministic payment authorizer for smoke and e2e lanes only. Authorizes the
 * `test.ap2.psp` handler when the credential carries the fixture payment mandate.
 * Never registered in prod; additionally gated by SWAG_AGENTIC_COMMERCE_TEST_AP2.
 */
final class FixtureAp2PaymentAuthorizer implements PaymentAuthorizerInterface
{
    public const HANDLER_ID = 'test.ap2.psp';

    public function __construct(
        private readonly bool $enabled,
    ) {
    }

    public function supports(string $handlerId): bool
    {
        return self::HANDLER_ID === $handlerId;
    }

    public function authorize(
        CheckoutCompleteRequest $request,
        PaymentInstrument $instrument,
        Cart $cart,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): PaymentAuthorizationResult {
        if ($this->enabled && 'fixture_payment_mandate' === ($instrument->credential['token'] ?? null)) {
            return PaymentAuthorizationResult::authorized('fixture-authorization');
        }

        return PaymentAuthorizationResult::failed('payment_declined', 'Fixture payment mandate was declined.');
    }
}
