<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Quote;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Capability\QuoteCapability;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Capability\UcpExtensionAvailability;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Identity\AgentCustomerCredential;
use Swag\AgenticCommerce\Ucp\Quote\QuoteBackendFeature;
use Swag\AgenticCommerce\Ucp\Quote\QuoteGatewayInterface;
use Swag\AgenticCommerce\Ucp\Quote\QuoteSnapshot;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
#[CoversClass(QuoteCapability::class)]
#[CoversClass(QuoteSnapshot::class)]
#[CoversClass(QuoteBackendFeature::class)]
final class QuoteCapabilityTest extends TestCase
{
    #[Test]
    public function testItExposesTheVendorQuoteDescriptor(): void
    {
        self::assertSame(
            UcpCapabilityCatalog::DESCRIPTOR_QUOTE,
            (new QuoteCapability())->describe()->name,
        );
    }

    #[Test]
    public function testItRejectsCallsWhenTheCapabilityIsDisabledForTheSalesChannel(): void
    {
        $capability = new QuoteCapability($this->createMock(QuoteGatewayInterface::class));

        $this->expectException(UnsupportedCapabilityException::class);
        $capability->getQuote($this->credential(), 'quote-id', $this->context([]));
    }

    #[Test]
    public function testItRejectsCallsWithoutACommercialBackend(): void
    {
        $capability = new QuoteCapability();

        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessageMatches('/commercial B2B quote backend/');
        $capability->getQuote($this->credential(), 'quote-id', $this->enabledContext());
    }

    #[Test]
    public function testItDelegatesEnabledCallsToTheGateway(): void
    {
        $snapshot = $this->snapshot();
        $gateway = $this->createMock(QuoteGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('requestQuote')
            ->with(self::isInstanceOf(AgentCustomerCredential::class), [['product_id' => 'product-id', 'quantity' => 5]], 'volume pricing please')
            ->willReturn($snapshot);

        $capability = new QuoteCapability($gateway);

        self::assertSame($snapshot, $capability->requestQuote(
            $this->credential(),
            [['product_id' => 'product-id', 'quantity' => 5]],
            'volume pricing please',
            $this->enabledContext(),
        ));
    }

    #[Test]
    public function testItReportsQuotesAsUnsupportedWithoutAGateway(): void
    {
        $availability = new UcpExtensionAvailability([], $this->createMock(PaymentHandlerRegistryInterface::class));

        self::assertFalse($availability->supportsQuotes());
    }

    #[Test]
    public function testItReportsQuotesAsUnsupportedWhenTheBackendIsUnlicensed(): void
    {
        $gateway = $this->createMock(QuoteGatewayInterface::class);
        $gateway->method('isAvailable')->willReturn(false);

        $availability = new UcpExtensionAvailability([], $this->createMock(PaymentHandlerRegistryInterface::class), $gateway);

        self::assertFalse($availability->supportsQuotes());
    }

    #[Test]
    public function testItReportsQuotesAsSupportedWhenTheBackendIsLicensed(): void
    {
        $gateway = $this->createMock(QuoteGatewayInterface::class);
        $gateway->method('isAvailable')->willReturn(true);

        $availability = new UcpExtensionAvailability([], $this->createMock(PaymentHandlerRegistryInterface::class), $gateway);

        self::assertTrue($availability->supportsQuotes());
    }

    #[Test]
    public function testItDetectsNoCommercialBackendInAPlainInstallation(): void
    {
        // SwagCommercial is deliberately absent from this plugin's dependencies.
        self::assertFalse(QuoteBackendFeature::isAvailableByClass());
        self::assertFalse(QuoteBackendFeature::isLicensed());
    }

    #[Test]
    public function testItPublishesExpirationAndPriceSemanticsInTheSnapshot(): void
    {
        $payload = $this->snapshot()->toArray();

        // Always present so agents can act before an offer expires, even when the
        // merchant has not set a date yet.
        self::assertArrayHasKey('expiration_date', $payload);
        self::assertSame('2026-08-15T00:00:00+00:00', $payload['expiration_date']);
        self::assertSame(['gross' => 119.0, 'net' => 100.0, 'tax_status' => 'gross'], $payload['totals']);
        self::assertSame(9.5, $payload['line_items'][0]['requested_unit_price']);
        self::assertArrayNotHasKey('order', $payload);
    }

    #[Test]
    public function testItPublishesTheOrderReferenceOnceAccepted(): void
    {
        $accepted = new QuoteSnapshot(
            'quote-id',
            '1030',
            'accepted',
            null,
            'EUR',
            119.0,
            100.0,
            'gross',
            [],
            [],
            'order-id',
            '10001',
        );

        self::assertSame(['id' => 'order-id', 'order_number' => '10001'], $accepted->toArray()['order']);
        self::assertNull($accepted->toArray()['expiration_date']);
    }

    private function credential(): AgentCustomerCredential
    {
        return AgentCustomerCredential::fromAccessToken('ucp_access_test');
    }

    private function snapshot(): QuoteSnapshot
    {
        return new QuoteSnapshot(
            'quote-id',
            '1030',
            'replied',
            '2026-08-15T00:00:00+00:00',
            'EUR',
            119.0,
            100.0,
            'gross',
            [[
                'id' => 'line-item-id',
                'product_id' => 'product-id',
                'label' => 'Main product',
                'quantity' => 10,
                'unit_price' => 11.9,
                'total_price' => 119.0,
                'requested_unit_price' => 9.5,
            ]],
            [[
                'comment' => 'volume pricing please',
                'author' => 'buyer',
                'created_at' => '2026-07-27T10:00:00+00:00',
            ]],
        );
    }

    private function enabledContext(): RequestContext
    {
        return $this->context([UcpCapabilityCatalog::CONFIG_QUOTE]);
    }

    /**
     * @param list<string> $enabledCapabilities
     */
    private function context(array $enabledCapabilities): RequestContext
    {
        return new RequestContext(
            'https://merchant.example',
            runtimeConfiguration: UcpConfig::fromArray([
                'active' => true,
                'enabledCapabilities' => $enabledCapabilities,
            ])->toRuntimeConfiguration('https://merchant.example'),
        );
    }
}
