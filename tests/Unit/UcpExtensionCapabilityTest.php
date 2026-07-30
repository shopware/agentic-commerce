<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Capability\IdentityLinkingCapability;
use Swag\AgenticCommerce\Ucp\Capability\PaymentTokenizationCapability;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Capability\UcpExtensionAvailability;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Payment\ShopwareInvoicePaymentHandler;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
#[CoversClass(IdentityLinkingCapability::class)]
#[CoversClass(PaymentTokenizationCapability::class)]
#[CoversClass(UcpExtensionAvailability::class)]
#[CoversClass(ShopwareInvoicePaymentHandler::class)]
final class UcpExtensionCapabilityTest extends TestCase
{
    #[Test]
    public function testItExposesOptionalCapabilityDescriptors(): void
    {
        self::assertSame(
            UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING,
            (new IdentityLinkingCapability([]))->describe()->name,
        );

        self::assertSame(
            UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION,
            (new PaymentTokenizationCapability(new PaymentHandlerRegistryStub([])))->describe()->name,
        );
    }

    #[Test]
    public function testItKeepsExtensionAvailabilityFalseWithoutRealImplementations(): void
    {
        $availability = new UcpExtensionAvailability([], new PaymentHandlerRegistryStub([]));

        self::assertFalse($availability->supportsIdentityLinking());
        self::assertFalse($availability->supportsPaymentTokenization());
    }

    #[Test]
    public function testItDetectsTokenizingPaymentHandlers(): void
    {
        $availability = new UcpExtensionAvailability([], new PaymentHandlerRegistryStub([
            new PaymentHandlerStub(true),
        ]));

        self::assertTrue($availability->supportsPaymentTokenization());
    }

    #[Test]
    public function testItDoesNotTreatTheBundledInvoiceHandlerAsTokenizingPspSupport(): void
    {
        $handler = new ShopwareInvoicePaymentHandler();
        $availability = new UcpExtensionAvailability([], new PaymentHandlerRegistryStub([$handler]));

        self::assertFalse($handler->supportsTokenization());
        self::assertFalse($availability->supportsPaymentTokenization());
        self::assertNull($handler->tokenize(new PaymentInstrument('invoice', $handler->id()), $this->contextWithCapability(UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION)));
    }

    #[Test]
    public function testItFiltersDescriptorsTheInstallationCannotServe(): void
    {
        $availability = new UcpExtensionAvailability([], new PaymentHandlerRegistryStub([]));

        self::assertSame(
            [UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT],
            $availability->filterSupportedDescriptors([
                UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT,
                UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING,
                UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION,
                UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE,
            ]),
        );
    }

    #[Test]
    public function testItReportsPaymentAuthorizerAvailabilityPerHandler(): void
    {
        $authorizer = new class implements \Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizerInterface {
            public function supports(string $handlerId): bool
            {
                return 'test.psp' === $handlerId;
            }

            public function authorize(
                \Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest $request,
                PaymentInstrument $instrument,
                \Shopware\Core\Checkout\Cart\Cart $cart,
                \Shopware\Core\System\SalesChannel\SalesChannelContext $context,
                RequestContext $requestContext,
            ): \Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizationResult {
                throw new \BadMethodCallException('Not called in this test.');
            }
        };

        $availability = new UcpExtensionAvailability([], new PaymentHandlerRegistryStub([]), [], [$authorizer]);

        self::assertTrue($availability->hasPaymentAuthorizerFor('test.psp'));
        self::assertFalse($availability->hasPaymentAuthorizerFor('other.psp'));
    }

    #[Test]
    public function testItRejectsTokenizationWithoutSupportingHandler(): void
    {
        $capability = new PaymentTokenizationCapability(new PaymentHandlerRegistryStub([]));

        $this->expectException(UnsupportedCapabilityException::class);

        $capability->tokenize(
            new PaymentInstrument('card', 'missing.handler'),
            $this->contextWithCapability(UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION),
        );
    }

    #[Test]
    public function testItDelegatesTokenizationToTheMatchingPaymentHandler(): void
    {
        $capability = new PaymentTokenizationCapability(new PaymentHandlerRegistryStub([
            new PaymentHandlerStub(true),
        ]));

        self::assertSame([
            'token' => 'tok_test',
            'handler_id' => 'test.handler',
            'type' => 'card',
        ], $capability->tokenize(
            new PaymentInstrument('card', 'test.handler'),
            $this->contextWithCapability(UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION),
        ));
    }

    private function contextWithCapability(string $capability): RequestContext
    {
        return new RequestContext(
            'https://merchant.example',
            runtimeConfiguration: UcpConfig::fromArray([
                'active' => true,
                'enabledCapabilities' => [$capability],
            ])->toRuntimeConfiguration('https://merchant.example'),
        );
    }
}

/** @internal */
final class PaymentHandlerRegistryStub implements PaymentHandlerRegistryInterface
{
    /**
     * @param list<PaymentHandlerInterface> $handlers
     */
    public function __construct(
        private readonly array $handlers,
    ) {
    }

    public function all(): array
    {
        return $this->handlers;
    }

    public function find(string $name): ?PaymentHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->id() === $name) {
                return $handler;
            }
        }

        return null;
    }
}

/** @internal */
final class PaymentHandlerStub implements PaymentHandlerInterface
{
    public function __construct(
        private readonly bool $supportsTokenization,
    ) {
    }

    public function id(): string
    {
        return 'test.handler';
    }

    public function describe(RequestContext $context): PaymentHandlerDescriptor
    {
        return new PaymentHandlerDescriptor(
            $this->id(),
            $this->id(),
            '2026-04-08',
            'https://ucp.dev/specification/payment-handler-guide/',
            'https://ucp.dev/schemas/payments/delegate-payment.json',
            ['https://ucp.dev/schemas/shopping/types/card_payment_instrument.json'],
        );
    }

    public function prepareInstrument(PaymentInstrument $instrument, RequestContext $context): array
    {
        return [
            'paymentMethodId' => 'payment-method-id',
            'token' => 'prepared_test',
        ];
    }

    public function supportsTokenization(): bool
    {
        return $this->supportsTokenization;
    }

    public function tokenize(PaymentInstrument $instrument, RequestContext $context): ?array
    {
        if (!$this->supportsTokenization) {
            return null;
        }

        return [
            'token' => 'tok_test',
            'handler_id' => $instrument->handlerId,
            'type' => $instrument->type,
        ];
    }
}
