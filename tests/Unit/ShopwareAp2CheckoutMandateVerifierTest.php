<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2CheckoutLockReaderInterface;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2MandateClaimsVerifierInterface;
use Swag\AgenticCommerce\Ucp\Ap2\ShopwareAp2CheckoutMandateVerifier;
use Swag\AgenticCommerce\Ucp\Ap2\ShopwareCheckoutTermsFactory;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\Ap2Exception;
use Ucp\Sdk\Model\Ap2\Ap2CheckoutData;
use Ucp\Sdk\Model\Ap2\Ap2VerificationResult;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareAp2CheckoutMandateVerifierTest extends TestCase
{
    #[Test]
    public function testItRejectsMissingMandateWhenCheckoutIsAp2Locked(): void
    {
        $verifier = $this->verifier(ap2Locked: true);

        try {
            $verifier->verify(
                new CheckoutCompleteRequest('checkout-1'),
                $this->checkout('checkout-1', 1299.0),
                new RequestContext('shop.example'),
            );
            self::fail('Expected Ap2Exception was not thrown.');
        } catch (Ap2Exception $exception) {
            self::assertSame('mandate_required', $exception->errorCode);
            self::assertSame('AP2 checkout mandate is required.', $exception->getMessage());
        }
    }

    #[Test]
    public function testItIgnoresUnlockedCheckoutsWithoutMandates(): void
    {
        $verifier = $this->verifier(ap2Locked: false);

        $verifier->verify(
            new CheckoutCompleteRequest('checkout-1'),
            $this->checkout('checkout-1', 1299.0),
            new RequestContext('shop.example'),
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testItRejectsMandateScopeMismatch(): void
    {
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: [
            'checkout_id' => 'checkout-1',
            'total' => ['amount' => 999, 'currency' => 'EUR'],
        ]);

        try {
            $verifier->verify(
                new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
                $this->checkout('checkout-1', 1299.0),
                new RequestContext('shop.example'),
            );
            self::fail('Expected Ap2Exception was not thrown.');
        } catch (Ap2Exception $exception) {
            self::assertSame('mandate_scope_mismatch', $exception->errorCode);
            self::assertSame('AP2 checkout mandate does not match current checkout terms.', $exception->getMessage());
        }
    }

    #[Test]
    public function testItRejectsMandatesForOtherCheckouts(): void
    {
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: [
            'checkout_id' => 'checkout-2',
            'total' => ['amount' => 129900, 'currency' => 'EUR'],
        ]);

        $this->expectException(Ap2Exception::class);
        $this->expectExceptionMessage('AP2 checkout mandate does not match current checkout terms.');

        $verifier->verify(
            new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
            $this->checkout('checkout-1', 1299.0),
            new RequestContext('shop.example'),
        );
    }

    #[Test]
    public function testItAcceptsMandatesCoveringTheCurrentCheckoutTerms(): void
    {
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: [
            'checkout_id' => 'checkout-1',
            'currency' => 'EUR',
            'total' => ['amount' => 129900, 'currency' => 'EUR'],
            'line_items' => [['id' => 'sku-1', 'quantity' => 1]],
        ]);

        $verifier->verify(
            new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
            $this->checkout('checkout-1', 1299.0),
            new RequestContext('shop.example'),
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testItRejectsExpiredMandates(): void
    {
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: [
            'checkout_id' => 'checkout-1',
            'total' => ['amount' => 129900, 'currency' => 'EUR'],
            'exp' => time() - 60,
        ]);

        try {
            $verifier->verify(
                new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
                $this->checkout('checkout-1', 1299.0),
                new RequestContext('shop.example'),
            );
            self::fail('Expected Ap2Exception was not thrown.');
        } catch (Ap2Exception $exception) {
            self::assertSame('mandate_expired', $exception->errorCode);
        }
    }

    #[Test]
    public function testItRejectsMandatesWithoutARegisteredClaimsVerifier(): void
    {
        $verifier = $this->verifier(ap2Locked: false);

        try {
            $verifier->verify(
                new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
                $this->checkout('checkout-1', 1299.0),
                new RequestContext('shop.example'),
            );
            self::fail('Expected Ap2Exception was not thrown.');
        } catch (Ap2Exception $exception) {
            self::assertSame('mandate_unsupported', $exception->errorCode);
        }
    }

    #[Test]
    public function testItMapsFailedMandateVerificationToItsErrorCode(): void
    {
        $verifier = $this->verifier(ap2Locked: true, verificationResult: Ap2VerificationResult::failed('mandate_invalid', 'Bad key binding.'));

        try {
            $verifier->verify(
                new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
                $this->checkout('checkout-1', 1299.0),
                new RequestContext('shop.example'),
            );
            self::fail('Expected Ap2Exception was not thrown.');
        } catch (Ap2Exception $exception) {
            self::assertSame('mandate_invalid', $exception->errorCode);
        }
    }

    /**
     * @param array<string, mixed>|null $verifiedClaims
     */
    private function verifier(
        bool $ap2Locked,
        ?array $verifiedClaims = null,
        ?Ap2VerificationResult $verificationResult = null,
    ): ShopwareAp2CheckoutMandateVerifier {
        $lockReader = new class($ap2Locked) implements Ap2CheckoutLockReaderInterface {
            public function __construct(private readonly bool $locked)
            {
            }

            public function isLocked(string $checkoutId, RequestContext $context): bool
            {
                return $this->locked;
            }
        };

        $result = $verificationResult ?? (null !== $verifiedClaims ? Ap2VerificationResult::verified($verifiedClaims) : null);
        $claimsVerifiers = [];
        if (null !== $result) {
            $claimsVerifiers[] = new class($result) implements Ap2MandateClaimsVerifierInterface {
                public function __construct(private readonly Ap2VerificationResult $result)
                {
                }

                public function verify(string $checkoutMandate, RequestContext $context): Ap2VerificationResult
                {
                    return $this->result;
                }
            };
        }

        return new ShopwareAp2CheckoutMandateVerifier($lockReader, new ShopwareCheckoutTermsFactory(), $claimsVerifiers);
    }

    private function checkout(string $id, float $total): Checkout
    {
        return new Checkout(
            $id,
            CheckoutStatus::ReadyForComplete,
            'EUR',
            [new LineItem('sku-1', 'Product', $total, 1)],
            [
                new Money('subtotal', $total),
                new Money('fulfillment', 0.0),
                new Money('total', $total),
                new Money('tax', 0.0),
            ],
        );
    }
}
