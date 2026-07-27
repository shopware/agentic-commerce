<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\UcpProtocol;

/** @internal */
final class UcpCapabilityCatalogTest extends TestCase
{
    #[Test]
    public function testItMapsConfigKeysToDescriptorNames(): void
    {
        self::assertSame([
            UcpCapabilityCatalog::DESCRIPTOR_CATALOG,
            UcpCapabilityCatalog::DESCRIPTOR_ORDER,
            UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING,
            UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION,
        ], UcpCapabilityCatalog::descriptorNamesForConfigKeys([
            UcpCapabilityCatalog::CONFIG_CATALOG,
            UcpCapabilityCatalog::CONFIG_ORDER,
            UcpCapabilityCatalog::CONFIG_IDENTITY_LINKING,
            UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION,
            'unknown',
        ]));
    }

    #[Test]
    public function testItKeepsExtensionCapabilitiesOutOfTheDefaultShippingContract(): void
    {
        self::assertSame([
            UcpCapabilityCatalog::CONFIG_CATALOG,
            UcpCapabilityCatalog::CONFIG_CART,
            UcpCapabilityCatalog::CONFIG_DISCOUNT,
            UcpCapabilityCatalog::CONFIG_CHECKOUT,
            UcpCapabilityCatalog::CONFIG_ORDER,
        ], UcpCapabilityCatalog::defaultConfigKeys());
    }

    #[Test]
    public function testItBuildsCapabilityDescriptorsFromCentralMetadata(): void
    {
        $descriptor = UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_CHECKOUT);
        $discount = UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_DISCOUNT);

        self::assertSame(UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT, $descriptor->name);
        self::assertSame(UcpProtocol::VERSION, $descriptor->version);
        self::assertSame('https://ucp.dev/specification/checkout/', $descriptor->specUrl);
        self::assertSame('https://ucp.dev/2026-04-08/schemas/shopping/checkout.json', $descriptor->schemaUrl);
        self::assertSame([
            UcpCapabilityCatalog::DESCRIPTOR_CART,
            UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT,
        ], $discount->extends);
    }

    #[Test]
    public function testItBuildsOptionalCapabilityDescriptorsFromCentralMetadata(): void
    {
        $identity = UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_IDENTITY_LINKING);
        $tokenization = UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION);

        self::assertSame(UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING, $identity->name);
        self::assertSame('https://ucp.dev/specification/identity-linking/', $identity->specUrl);
        self::assertSame('https://ucp.dev/2026-04-08/schemas/identity/oauth.json', $identity->schemaUrl);
        self::assertSame(UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION, $tokenization->name);
        self::assertSame('https://ucp.dev/specification/payment-token-exchange/', $tokenization->specUrl);
        self::assertSame('https://ucp.dev/2026-04-08/schemas/shopping/payment-tokenization.json', $tokenization->schemaUrl);
    }

    #[Test]
    public function testItDescribesTheVendorQuoteCapabilityOutsideTheUcpDevNamespace(): void
    {
        $quote = UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_QUOTE);

        self::assertSame('com.shopware.quote', $quote->name);
        self::assertStringStartsNotWith('dev.ucp.', $quote->name);
        self::assertSame(UcpProtocol::VERSION, $quote->version);
        // The vendor contract is not hosted on ucp.dev; the plugin serves it itself.
        self::assertStringNotContainsString('ucp.dev', $quote->schemaUrl);
        self::assertSame(UcpCapabilityCatalog::QUOTE_SCHEMA_PATH, $quote->schemaUrl);
        self::assertNull($quote->extends);
    }

    #[Test]
    public function testItResolvesTheQuoteSchemaUrlAgainstTheShopBaseUri(): void
    {
        self::assertSame(
            'https://shop.example/.well-known/ucp/schemas/quote.openapi.json',
            UcpCapabilityCatalog::quoteSchemaUrl('https://shop.example'),
        );

        self::assertSame(
            'https://shop.example/.well-known/ucp/schemas/quote.openapi.json',
            UcpCapabilityCatalog::quoteSchemaUrl('https://shop.example/'),
        );
    }

    #[Test]
    public function testItAcceptsTheQuoteConfigKeyWithoutShippingItByDefault(): void
    {
        self::assertContains(UcpCapabilityCatalog::CONFIG_QUOTE, UcpCapabilityCatalog::allConfigKeys());
        self::assertNotContains(UcpCapabilityCatalog::CONFIG_QUOTE, UcpCapabilityCatalog::defaultConfigKeys());
    }
}
