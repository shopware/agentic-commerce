<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2MerchantAuthorizationResponseAugmenter;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CheckoutMerchantAuthorizationSignerInterface;

/** @internal */
final class Ap2MerchantAuthorizationResponseAugmenterTest extends TestCase
{
    #[Test]
    public function testItSignsCheckoutResponsesWhenAp2WasNegotiated(): void
    {
        $signer = new class implements CheckoutMerchantAuthorizationSignerInterface {
            /** @var array<string, mixed>|null */
            public ?array $signedPayload = null;

            public function sign(array $checkoutPayload, RequestContext $context): string
            {
                $this->signedPayload = $checkoutPayload;

                return 'header..signature';
            }
        };

        $augmenter = new Ap2MerchantAuthorizationResponseAugmenter($signer);
        $checkout = new Checkout('checkout-1', CheckoutStatus::ReadyForComplete, 'EUR', [], []);

        $augmented = $augmenter->augment($checkout, $this->ap2Context());

        self::assertSame('header..signature', $augmented->extra['ap2']['merchant_authorization']);
        self::assertSame('checkout-1', $signer->signedPayload['id'] ?? null);
        self::assertSame('header..signature', $augmented->toArray()['ap2']['merchant_authorization']);
    }

    #[Test]
    public function testItLeavesResponsesUntouchedWithoutAp2Negotiation(): void
    {
        $signer = $this->createMock(CheckoutMerchantAuthorizationSignerInterface::class);
        $signer->expects(static::never())->method('sign');

        $augmenter = new Ap2MerchantAuthorizationResponseAugmenter($signer);
        $checkout = new Checkout('checkout-1', CheckoutStatus::ReadyForComplete, 'EUR', [], []);

        self::assertSame($checkout, $augmenter->augment($checkout, new RequestContext('shop.example')));
    }

    #[Test]
    public function testItSkipsSigningWhenNoActiveKeyExists(): void
    {
        $signer = $this->createMock(CheckoutMerchantAuthorizationSignerInterface::class);
        $signer->method('sign')->willThrowException(new SignatureException('No active signing key.'));

        $augmenter = new Ap2MerchantAuthorizationResponseAugmenter($signer);
        $checkout = new Checkout('checkout-1', CheckoutStatus::ReadyForComplete, 'EUR', [], []);

        self::assertSame($checkout, $augmenter->augment($checkout, $this->ap2Context()));
    }

    private function ap2Context(): RequestContext
    {
        return new RequestContext('shop.example', negotiation: new NegotiatedCapabilities([
            UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE => [
                new CapabilityDescriptor(UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE, '2026-04-08', 'spec', 'schema'),
            ],
        ]));
    }
}
