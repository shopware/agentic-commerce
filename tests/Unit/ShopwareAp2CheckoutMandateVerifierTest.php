<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2CheckoutLockReaderInterface;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2MandateClaimsVerifierInterface;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2VerificationResult;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2VerifiedMandateRegistry;
use Swag\AgenticCommerce\Ucp\Ap2\ShopwareAp2CheckoutMandateVerifier;
use Swag\AgenticCommerce\Ucp\Ap2\ShopwareCheckoutTermsFactory;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\Ap2Exception;
use Ucp\Sdk\Model\Ap2\Ap2CheckoutData;
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
    public function testItRejectsMandatesThatPinNoTotal(): void
    {
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: [
            'checkout_id' => 'checkout-1',
        ]);

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

    #[Test]
    public function testItRejectsBareTotalsWithoutACurrencyPin(): void
    {
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: [
            'checkout_id' => 'checkout-1',
            'total' => 129900,
        ]);

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

    #[Test]
    public function testItAcceptsLineItemsInAnyOrder(): void
    {
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: [
            'checkout_id' => 'checkout-1',
            'total' => ['amount' => 259900, 'currency' => 'EUR'],
            'line_items' => [
                ['id' => 'sku-2', 'quantity' => 1],
                ['id' => 'sku-1', 'quantity' => 1],
            ],
        ]);

        $verifier->verify(
            new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
            $this->twoItemCheckout('checkout-1'),
            new RequestContext('shop.example'),
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testItRejectsClaimedUnitPricesThatDoNotMatchTheCheckout(): void
    {
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: [
            'checkout_id' => 'checkout-1',
            'total' => ['amount' => 129900, 'currency' => 'EUR'],
            'line_items' => [['id' => 'sku-1', 'quantity' => 1, 'unit_price' => 99900]],
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
        }
    }

    #[Test]
    public function testItConsultsAllRegisteredClaimsVerifiers(): void
    {
        $failing = new class implements Ap2MandateClaimsVerifierInterface {
            public function verify(string $checkoutMandate, RequestContext $context): Ap2VerificationResult
            {
                return Ap2VerificationResult::failed('mandate_invalid', 'Unknown mandate format.');
            }
        };

        $succeeding = new class implements Ap2MandateClaimsVerifierInterface {
            public function verify(string $checkoutMandate, RequestContext $context): Ap2VerificationResult
            {
                return Ap2VerificationResult::verified([
                    'checkout_id' => 'checkout-1',
                    'total' => ['amount' => 129900, 'currency' => 'EUR'],
                ]);
            }
        };

        $lockReader = new class implements Ap2CheckoutLockReaderInterface {
            public function isLocked(string $checkoutId, RequestContext $context): bool
            {
                return true;
            }
        };

        $verifier = new ShopwareAp2CheckoutMandateVerifier($lockReader, new ShopwareCheckoutTermsFactory(), [$failing, $succeeding]);

        $verifier->verify(
            new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
            $this->checkout('checkout-1', 1299.0),
            new RequestContext('shop.example'),
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function testItComparesZeroDecimalCurrenciesInIsoMinorUnits(): void
    {
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: [
            'checkout_id' => 'checkout-1',
            'total' => ['amount' => 1000, 'currency' => 'JPY'],
        ]);

        $checkout = new Checkout(
            'checkout-1',
            CheckoutStatus::ReadyForComplete,
            'JPY',
            [new LineItem('sku-1', 'Product', 1000.0, 1, currency: 'JPY')],
            [
                new Money('subtotal', 1000.0, null, 'JPY'),
                new Money('fulfillment', 0.0, null, 'JPY'),
                new Money('total', 1000.0, null, 'JPY'),
                new Money('tax', 0.0, null, 'JPY'),
            ],
        );

        $verifier->verify(
            new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
            $checkout,
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

    #[Test]
    public function testItSupportsOnlyRequestsCarryingACheckoutMandate(): void
    {
        $verifier = $this->verifier(ap2Locked: true);
        $checkout = $this->checkout('checkout-1', 1299.0);
        $context = new RequestContext('shop.example');

        self::assertTrue($verifier->supports(
            new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
            $checkout,
            $context,
        ));
        self::assertFalse($verifier->supports(
            new CheckoutCompleteRequest('checkout-1'),
            $checkout,
            $context,
        ));
    }

    #[Test]
    public function testItRecordsVerifiedMandatesInTheRegistry(): void
    {
        $registry = new Ap2VerifiedMandateRegistry();
        $claims = [
            'checkout_id' => 'checkout-1',
            'total' => ['amount' => 129900, 'currency' => 'EUR'],
        ];
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: $claims, mandateRegistry: $registry);

        $verifier->verify(
            new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate-token')),
            $this->checkout('checkout-1', 1299.0),
            new RequestContext('shop.example'),
        );

        $verified = $registry->forCheckout('checkout-1');
        self::assertNotNull($verified);
        self::assertSame('mandate-token', $verified['checkoutMandate']);
        self::assertSame($claims, $verified['claims']);
    }

    #[Test]
    public function testItDoesNotRecordRejectedMandates(): void
    {
        $registry = new Ap2VerifiedMandateRegistry();
        $verifier = $this->verifier(ap2Locked: true, verifiedClaims: [
            'checkout_id' => 'checkout-1',
            'total' => ['amount' => 999, 'currency' => 'EUR'],
        ], mandateRegistry: $registry);

        try {
            $verifier->verify(
                new CheckoutCompleteRequest('checkout-1', ap2: new Ap2CheckoutData('mandate')),
                $this->checkout('checkout-1', 1299.0),
                new RequestContext('shop.example'),
            );
            self::fail('Expected Ap2Exception was not thrown.');
        } catch (Ap2Exception) {
        }

        self::assertNull($registry->forCheckout('checkout-1'));
    }

    /**
     * @param array<string, mixed>|null $verifiedClaims
     */
    private function verifier(
        bool $ap2Locked,
        ?array $verifiedClaims = null,
        ?Ap2VerificationResult $verificationResult = null,
        ?Ap2VerifiedMandateRegistry $mandateRegistry = null,
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

        return new ShopwareAp2CheckoutMandateVerifier($lockReader, new ShopwareCheckoutTermsFactory(), $claimsVerifiers, $mandateRegistry);
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

    private function twoItemCheckout(string $id): Checkout
    {
        return new Checkout(
            $id,
            CheckoutStatus::ReadyForComplete,
            'EUR',
            [
                new LineItem('sku-1', 'Product 1', 1299.0, 1),
                new LineItem('sku-2', 'Product 2', 1300.0, 1),
            ],
            [
                new Money('subtotal', 2599.0),
                new Money('fulfillment', 0.0),
                new Money('total', 2599.0),
                new Money('tax', 0.0),
            ],
        );
    }
}
