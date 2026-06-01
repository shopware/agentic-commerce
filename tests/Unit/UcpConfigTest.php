<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Enum\Transport;

/** @internal */
final class UcpConfigTest extends TestCase
{
    #[Test]
    public function testItNormalizesArraysAndCustomProfileUri(): void
    {
        $config = UcpConfig::fromArray([
            'active' => true,
            'customProfileUri' => 'https://merchant.example/ucp',
            'profileUriStrategy' => 'config',
            'enabledTransports' => ['rest', '', 'rest'],
            'platformAllowlist' => ['merchant.example', 'merchant.example'],
            'remoteProfileAllowlist' => ['platform.example', 'platform.example'],
            'agentAllowlist' => ['agent.example'],
            'embeddedAllowedOrigins' => ['https://assistant.example'],
            'embeddedFrameAncestors' => ['https://assistant.example'],
            'signaturePolicy' => 'strict',
        ]);

        self::assertTrue($config->active);
        self::assertSame('https://merchant.example/ucp', $config->resolveBaseUri('https://fallback.example'));
        self::assertSame(['rest'], $config->enabledTransports);
        self::assertSame(['rest'], array_map(static fn ($transport): string => $transport->value, $config->runtimeTransports()));
        self::assertSame(['merchant.example'], $config->platformAllowlist);
        self::assertSame(['platform.example'], $config->remoteProfileAllowlist);
        self::assertSame(['agent.example'], $config->agentAllowlist);
        self::assertSame(['https://assistant.example'], $config->embeddedAllowedOrigins);
        self::assertSame(['https://assistant.example'], $config->embeddedFrameAncestors);
        self::assertSame('strict', $config->signaturePolicy);
        self::assertSame(UcpProtocol::VERSION, $config->ucpVersion);
    }

    #[Test]
    public function testItEnablesDefaultCapabilitiesWhenNoneAreStored(): void
    {
        $config = UcpConfig::fromArray([
            'active' => true,
        ]);

        self::assertSame(UcpCapabilityCatalog::defaultConfigKeys(), $config->enabledCapabilities);
        self::assertTrue($config->idempotencyRequired);
        self::assertSame('strict', $config->signaturePolicy);
        self::assertSame([
            UcpCapabilityCatalog::DESCRIPTOR_CATALOG,
            UcpCapabilityCatalog::DESCRIPTOR_CART,
            UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT,
            UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT,
            UcpCapabilityCatalog::DESCRIPTOR_ORDER,
        ], $config->runtimeEnabledCapabilityDescriptors());
    }

    #[Test]
    public function testItFiltersMcpUntilStoreApiMcpIsAvailable(): void
    {
        $config = UcpConfig::fromArray([
            'active' => true,
            'enabledTransports' => ['rest', 'mcp', 'a2a', 'embedded'],
        ]);

        self::assertSame([Transport::Rest, Transport::A2a, Transport::Embedded], $config->runtimeTransports());
        self::assertSame([Transport::Rest, Transport::Mcp, Transport::A2a, Transport::Embedded], $config->runtimeTransports(true));
        self::assertSame([
            'mcp' => 'https://merchant.example/store-api/_mcp',
        ], $config->transportEndpoints('https://merchant.example', true));
    }

    #[Test]
    public function testItCanStoreOptionalExtensionCapabilitiesWithoutDefaultingThemOn(): void
    {
        $config = UcpConfig::fromArray([
            'active' => true,
            'enabledCapabilities' => [
                UcpCapabilityCatalog::CONFIG_CATALOG,
                UcpCapabilityCatalog::CONFIG_IDENTITY_LINKING,
                UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION,
            ],
        ]);

        self::assertSame([
            UcpCapabilityCatalog::DESCRIPTOR_CATALOG,
            UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING,
            UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION,
        ], $config->runtimeEnabledCapabilityDescriptors());
    }

    #[Test]
    public function testItFallsBackToStrictForInvalidSignaturePolicy(): void
    {
        $config = UcpConfig::fromArray([
            'signaturePolicy' => 'invalid',
        ]);

        self::assertSame('strict', $config->signaturePolicy);
    }

    #[Test]
    public function testItNormalizesUnknownCapabilitiesAndTransportsBeforeStorage(): void
    {
        $config = UcpConfig::fromArray([
            'enabledCapabilities' => [
                UcpCapabilityCatalog::CONFIG_CATALOG,
                'unknown',
                UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION,
            ],
            'enabledTransports' => ['rest', 'invalid', 'a2a', 'rest'],
        ]);

        self::assertSame([
            UcpCapabilityCatalog::CONFIG_CATALOG,
            UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION,
        ], $config->enabledCapabilities);
        self::assertSame(['rest', 'a2a'], $config->enabledTransports);
    }

    #[Test]
    public function testItSplitsRuntimeAllowlistsButKeepsLegacyFallback(): void
    {
        $splitConfig = UcpConfig::fromArray([
            'remoteProfileAllowlist' => ['platform.example'],
            'agentAllowlist' => ['agent.example'],
        ])->toRuntimeConfiguration('https://merchant.example');

        self::assertSame(['platform.example'], $splitConfig->allowedProfileHosts);
        self::assertSame(['agent.example'], $splitConfig->allowedAgentDomains);

        $legacyConfig = UcpConfig::fromArray([
            'platformAllowlist' => ['legacy.example'],
        ])->toRuntimeConfiguration('https://merchant.example');

        self::assertSame(['legacy.example'], $legacyConfig->allowedProfileHosts);
        self::assertSame(['legacy.example'], $legacyConfig->allowedAgentDomains);
    }
}
