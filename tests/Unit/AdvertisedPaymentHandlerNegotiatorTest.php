<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AdvertisedPaymentHandlers;
use Swag\AgenticCommerce\Ucp\Negotiation\AdvertisedPaymentHandlerNegotiator;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityNegotiatorInterface;

/** @internal */
#[CoversClass(AdvertisedPaymentHandlerNegotiator::class)]
final class AdvertisedPaymentHandlerNegotiatorTest extends TestCase
{
    private CapabilityNegotiatorInterface&MockObject $inner;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(CapabilityNegotiatorInterface::class);
    }

    #[Test]
    public function testAHandlerTheProfileDoesNotAdvertiseIsRemovedFromTheNegotiatedIdsAndDescriptors(): void
    {
        $capabilities = ['dev.ucp.shopping.checkout' => [new CapabilityDescriptor('dev.ucp.shopping.checkout', '2026-08-25', 'https://ucp.dev/spec', 'https://ucp.dev/schema.json')]];
        $this->inner->method('negotiate')->willReturn(new NegotiatedCapabilities(
            $capabilities,
            ['com.shopware.invoice', 'com.shopware.prepayment', 'com.example.psp'],
            ['checkout.complete' => ['dev.ucp.shopping.checkout']],
            [
                'com.shopware.invoice' => [$this->descriptor('com.shopware.invoice')],
                'com.shopware.prepayment' => [$this->descriptor('com.shopware.prepayment')],
                'com.example.psp' => [$this->descriptor('com.example.psp')],
            ],
        ));

        $negotiated = $this->negotiator('com.shopware.invoice', 'com.example.psp')->negotiate(null, $this->requestContext());

        self::assertSame(['com.shopware.invoice', 'com.example.psp'], $negotiated->paymentHandlerIds);
        self::assertSame(['com.shopware.invoice', 'com.example.psp'], array_keys($negotiated->paymentHandlers));
        self::assertSame($capabilities, $negotiated->capabilities);
        self::assertSame(['checkout.complete' => ['dev.ucp.shopping.checkout']], $negotiated->operationCapabilityMap);
    }

    #[Test]
    public function testOnlyTheDescriptorsOfAdvertisedIdsStayUnderANameThatSeveralHandlersShare(): void
    {
        $this->inner->method('negotiate')->willReturn(new NegotiatedCapabilities(
            [],
            ['com.example.card.visa', 'com.example.card.amex'],
            [],
            ['com.example.card' => [$this->descriptor('com.example.card.visa', 'com.example.card'), $this->descriptor('com.example.card.amex', 'com.example.card')]],
        ));

        $negotiated = $this->negotiator('com.example.card.amex')->negotiate(null, $this->requestContext());

        self::assertSame(['com.example.card.amex'], $negotiated->paymentHandlerIds);
        self::assertSame(['com.example.card.amex'], array_map(
            static fn (PaymentHandlerDescriptor $descriptor): string => $descriptor->id,
            $negotiated->paymentHandlers['com.example.card'],
        ));
    }

    #[Test]
    public function testAProfileAdvertisingNoneOfTheNegotiatedHandlersLeavesNone(): void
    {
        $this->inner->method('negotiate')->willReturn(new NegotiatedCapabilities(
            [],
            ['com.shopware.invoice'],
            [],
            ['com.shopware.invoice' => [$this->descriptor('com.shopware.invoice')]],
        ));

        $negotiated = $this->negotiator()->negotiate(null, $this->requestContext());

        self::assertSame([], $negotiated->paymentHandlerIds);
        self::assertSame([], $negotiated->paymentHandlers);
    }

    #[Test]
    public function testNothingNegotiatedIsPassedThroughWithoutBuildingTheProfile(): void
    {
        $inner = new NegotiatedCapabilities(['dev.ucp.shopping.checkout' => []]);
        $this->inner->method('negotiate')->willReturn($inner);
        $negotiator = new AdvertisedPaymentHandlerNegotiator($this->inner, new AdvertisedPaymentHandlers(static function (): never {
            throw new \LogicException('The profile must not be built when no payment handler was negotiated.');
        }));

        self::assertSame($inner, $negotiator->negotiate(null, $this->requestContext()));
    }

    #[Test]
    public function testTheInnerNegotiatorReceivesTheAgentProfileAndTheContextOfTheRequest(): void
    {
        $platformProfile = new PlatformProfile('2026-08-25', [], [], []);
        $context = $this->requestContext();
        $this->inner->expects(static::once())->method('negotiate')->with($platformProfile, $context)->willReturn(new NegotiatedCapabilities());

        $this->negotiator()->negotiate($platformProfile, $context);
    }

    private function negotiator(string ...$advertisedHandlerIds): AdvertisedPaymentHandlerNegotiator
    {
        $profileBuilder = RecordingProfileBuilder::advertising(...$advertisedHandlerIds);

        return new AdvertisedPaymentHandlerNegotiator(
            $this->inner,
            new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $profileBuilder),
        );
    }

    private function descriptor(string $id, ?string $name = null): PaymentHandlerDescriptor
    {
        return new PaymentHandlerDescriptor($id, $name ?? $id, '2026-08-25', 'https://example.com/spec', 'https://example.com/config.json', ['https://example.com/instrument.json']);
    }

    private function requestContext(): RequestContext
    {
        return new RequestContext('shop.example', runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example'));
    }
}
