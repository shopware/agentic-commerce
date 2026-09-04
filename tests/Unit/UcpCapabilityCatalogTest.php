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
        self::assertSame('https://ucp.dev/2026-08-25/schemas/shopping/checkout.json', $descriptor->schemaUrl);
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
        self::assertSame('https://ucp.dev/2026-08-25/schemas/identity/oauth.json', $identity->schemaUrl);
        self::assertSame(UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION, $tokenization->name);
        self::assertSame('https://ucp.dev/specification/payment-token-exchange/', $tokenization->specUrl);
        self::assertSame('https://ucp.dev/2026-08-25/schemas/shopping/payment-tokenization.json', $tokenization->schemaUrl);
    }
}
