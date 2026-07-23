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
    public function testItBuildsAp2MandateDescriptor(): void
    {
        $descriptor = UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_AP2_MANDATE);

        self::assertSame('dev.ucp.shopping.ap2_mandate', $descriptor->name);
        self::assertSame([UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT], $descriptor->extends);
        self::assertSame('https://ucp.dev/latest/specification/ap2-mandates/', $descriptor->specUrl);
        self::assertNotContains(UcpCapabilityCatalog::CONFIG_AP2_MANDATE, UcpCapabilityCatalog::defaultConfigKeys());
        self::assertContains(UcpCapabilityCatalog::CONFIG_AP2_MANDATE, UcpCapabilityCatalog::allConfigKeys());
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
}
