<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Enum\Transport;

/** @internal */
final class UcpConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $expectedConfig
     * @param array<string, mixed> $expectedRuntime
     */
    #[DataProvider('adminConfigMatrixProvider')]
    #[Test]
    public function testItBuildsExpectedRuntimeConfigurationFromAdminConfig(
        array $payload,
        bool $storeApiMcpAvailable,
        string $fallbackBaseUri,
        array $expectedConfig,
        array $expectedRuntime,
    ): void {
        $config = UcpConfig::fromArray($payload);

        foreach ($expectedConfig as $property => $expectedValue) {
            self::assertSame($expectedValue, self::configProperty($config, $property), \sprintf('Failed asserting config property "%s".', $property));
        }

        $runtimeConfiguration = $config->toRuntimeConfiguration($fallbackBaseUri, 'sales-channel-id', $storeApiMcpAvailable);
        $actualTransports = array_map(
            static fn (Transport $transport): string => $transport->value,
            $runtimeConfiguration->transports,
        );

        self::assertSame($expectedRuntime['baseUri'], $runtimeConfiguration->baseUri);
        self::assertSame($expectedRuntime['signaturePolicy'], $runtimeConfiguration->signaturePolicy->value);
        self::assertSame($expectedRuntime['idempotencyRequired'], $runtimeConfiguration->idempotencyRequired);
        self::assertSame($expectedRuntime['allowedProfileHosts'], $runtimeConfiguration->allowedProfileHosts);
        self::assertSame($expectedRuntime['allowedAgentDomains'], $runtimeConfiguration->allowedAgentDomains);
        self::assertSame($expectedRuntime['enabledCapabilities'], $runtimeConfiguration->enabledCapabilities);
        self::assertSame($expectedRuntime['transports'], $actualTransports);
        self::assertSame($expectedRuntime['transportEndpoints'], $runtimeConfiguration->transportEndpoints);
        self::assertSame('sales-channel-id', $runtimeConfiguration->tenantIdentifier);
    }

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
            'mcp' => 'https://merchant.example/ucp/mcp',
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

    /**
     * @return iterable<string, array{
     *     payload: array<string, mixed>,
     *     storeApiMcpAvailable: bool,
     *     fallbackBaseUri: string,
     *     expectedConfig: array<string, mixed>,
     *     expectedRuntime: array<string, mixed>
     * }>
     */
    public static function adminConfigMatrixProvider(): iterable
    {
        $defaultCapabilities = UcpCapabilityCatalog::defaultConfigKeys();
        $defaultDescriptors = [
            UcpCapabilityCatalog::DESCRIPTOR_CATALOG,
            UcpCapabilityCatalog::DESCRIPTOR_CART,
            UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT,
            UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT,
            UcpCapabilityCatalog::DESCRIPTOR_ORDER,
        ];

        yield 'missing config uses legacy-compatible defaults but stays inactive at runtime' => [
            'payload' => [],
            'storeApiMcpAvailable' => false,
            'fallbackBaseUri' => 'https://merchant.example/shop',
            'expectedConfig' => [
                'active' => false,
                'enabledCapabilities' => $defaultCapabilities,
                'enabledTransports' => ['rest'],
                'signaturePolicy' => 'strict',
                'idempotencyRequired' => true,
                'customProfileUri' => null,
            ],
            'expectedRuntime' => [
                'baseUri' => 'https://merchant.example/shop',
                'signaturePolicy' => 'strict',
                'idempotencyRequired' => true,
                'allowedProfileHosts' => ['merchant.example'],
                'allowedAgentDomains' => ['merchant.example'],
                'enabledCapabilities' => [],
                'transports' => ['rest'],
                'transportEndpoints' => [],
            ],
        ];

        yield 'explicit empty admin arrays stay empty' => [
            'payload' => [
                'active' => true,
                'enabledCapabilities' => [],
                'enabledTransports' => [],
            ],
            'storeApiMcpAvailable' => false,
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedConfig' => [
                'active' => true,
                'enabledCapabilities' => [],
                'enabledTransports' => [],
            ],
            'expectedRuntime' => [
                'baseUri' => 'https://merchant.example',
                'signaturePolicy' => 'strict',
                'idempotencyRequired' => true,
                'allowedProfileHosts' => ['merchant.example'],
                'allowedAgentDomains' => ['merchant.example'],
                'enabledCapabilities' => [],
                'transports' => [],
                'transportEndpoints' => [],
            ],
        ];

        yield 'invalid and duplicate config values are sanitized' => [
            'payload' => [
                'active' => true,
                'enabledCapabilities' => ['catalog', 'unknown', 'payment_tokenization', 'catalog'],
                'enabledTransports' => ['rest', 'invalid', 'a2a', 'rest'],
                'signaturePolicy' => 'invalid',
                'idempotencyRequired' => false,
            ],
            'storeApiMcpAvailable' => false,
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedConfig' => [
                'enabledCapabilities' => ['catalog', 'payment_tokenization'],
                'enabledTransports' => ['rest', 'a2a'],
                'signaturePolicy' => 'strict',
                'idempotencyRequired' => false,
            ],
            'expectedRuntime' => [
                'baseUri' => 'https://merchant.example',
                'signaturePolicy' => 'strict',
                'idempotencyRequired' => false,
                'allowedProfileHosts' => ['merchant.example'],
                'allowedAgentDomains' => ['merchant.example'],
                'enabledCapabilities' => [
                    UcpCapabilityCatalog::DESCRIPTOR_CATALOG,
                    UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION,
                ],
                'transports' => ['rest', 'a2a'],
                'transportEndpoints' => [],
            ],
        ];

        yield 'all-invalid explicit config values stay empty after sanitization' => [
            'payload' => [
                'active' => true,
                'enabledCapabilities' => ['unknown', 'unsupported'],
                'enabledTransports' => ['invalid', 'other'],
            ],
            'storeApiMcpAvailable' => false,
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedConfig' => [
                'enabledCapabilities' => [],
                'enabledTransports' => [],
            ],
            'expectedRuntime' => [
                'baseUri' => 'https://merchant.example',
                'signaturePolicy' => 'strict',
                'idempotencyRequired' => true,
                'allowedProfileHosts' => ['merchant.example'],
                'allowedAgentDomains' => ['merchant.example'],
                'enabledCapabilities' => [],
                'transports' => [],
                'transportEndpoints' => [],
            ],
        ];

        yield 'domain strategy ignores stale custom profile URI for runtime base' => [
            'payload' => [
                'active' => true,
                'profileUriStrategy' => 'domain',
                'customProfileUri' => 'https://stale.example',
                'enabledCapabilities' => $defaultCapabilities,
                'enabledTransports' => ['rest'],
            ],
            'storeApiMcpAvailable' => false,
            'fallbackBaseUri' => 'https://merchant.example/domain',
            'expectedConfig' => [
                'profileUriStrategy' => 'domain',
                'customProfileUri' => 'https://stale.example',
            ],
            'expectedRuntime' => [
                'baseUri' => 'https://merchant.example/domain',
                'signaturePolicy' => 'strict',
                'idempotencyRequired' => true,
                'allowedProfileHosts' => ['merchant.example'],
                'allowedAgentDomains' => ['merchant.example'],
                'enabledCapabilities' => $defaultDescriptors,
                'transports' => ['rest'],
                'transportEndpoints' => [],
            ],
        ];

        yield 'custom profile URI and MCP endpoint are used when available' => [
            'payload' => [
                'active' => true,
                'profileUriStrategy' => 'config',
                'customProfileUri' => 'https://custom.example/',
                'enabledTransports' => ['rest', 'mcp'],
                'signaturePolicy' => 'log',
            ],
            'storeApiMcpAvailable' => true,
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedConfig' => [
                'profileUriStrategy' => 'config',
                'customProfileUri' => 'https://custom.example/',
                'enabledTransports' => ['rest', 'mcp'],
                'signaturePolicy' => 'log',
            ],
            'expectedRuntime' => [
                'baseUri' => 'https://custom.example',
                'signaturePolicy' => 'log',
                'idempotencyRequired' => true,
                'allowedProfileHosts' => ['custom.example'],
                'allowedAgentDomains' => ['custom.example'],
                'enabledCapabilities' => $defaultDescriptors,
                'transports' => ['rest', 'mcp'],
                'transportEndpoints' => [
                    'mcp' => 'https://custom.example/ucp/mcp',
                ],
            ],
        ];

        yield 'MCP remains persisted but is filtered when Store API MCP is unavailable' => [
            'payload' => [
                'active' => true,
                'enabledTransports' => ['rest', 'mcp', 'embedded'],
            ],
            'storeApiMcpAvailable' => false,
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedConfig' => [
                'enabledTransports' => ['rest', 'mcp', 'embedded'],
            ],
            'expectedRuntime' => [
                'baseUri' => 'https://merchant.example',
                'signaturePolicy' => 'strict',
                'idempotencyRequired' => true,
                'allowedProfileHosts' => ['merchant.example'],
                'allowedAgentDomains' => ['merchant.example'],
                'enabledCapabilities' => $defaultDescriptors,
                'transports' => ['rest', 'embedded'],
                'transportEndpoints' => [],
            ],
        ];

        yield 'split allowlists override legacy allowlist fallback' => [
            'payload' => [
                'active' => true,
                'platformAllowlist' => ['legacy.example'],
                'remoteProfileAllowlist' => ['platform.example'],
                'agentAllowlist' => ['agent.example'],
                'embeddedAllowedOrigins' => ['https://assistant.example'],
                'embeddedFrameAncestors' => ['https://frame.example'],
                'continueUrlTemplate' => 'https://merchant.example/checkout/confirm?checkoutId={checkoutId}',
                'webhookUrlOverride' => 'https://webhook.example/ucp',
                'discoveryBudget' => 5,
                'signaturePolicy' => 'off',
            ],
            'storeApiMcpAvailable' => false,
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedConfig' => [
                'platformAllowlist' => ['legacy.example'],
                'remoteProfileAllowlist' => ['platform.example'],
                'agentAllowlist' => ['agent.example'],
                'embeddedAllowedOrigins' => ['https://assistant.example'],
                'embeddedFrameAncestors' => ['https://frame.example'],
                'continueUrlTemplate' => 'https://merchant.example/checkout/confirm?checkoutId={checkoutId}',
                'webhookUrlOverride' => 'https://webhook.example/ucp',
                'discoveryBudget' => 5,
                'signaturePolicy' => 'off',
            ],
            'expectedRuntime' => [
                'baseUri' => 'https://merchant.example',
                'signaturePolicy' => 'off',
                'idempotencyRequired' => true,
                'allowedProfileHosts' => ['platform.example'],
                'allowedAgentDomains' => ['agent.example'],
                'enabledCapabilities' => $defaultDescriptors,
                'transports' => ['rest'],
                'transportEndpoints' => [],
            ],
        ];
    }

    private static function configProperty(UcpConfig $config, string $property): mixed
    {
        return match ($property) {
            'active' => $config->active,
            'enabledCapabilities' => $config->enabledCapabilities,
            'enabledTransports' => $config->enabledTransports,
            'signaturePolicy' => $config->signaturePolicy,
            'idempotencyRequired' => $config->idempotencyRequired,
            'customProfileUri' => $config->customProfileUri,
            'profileUriStrategy' => $config->profileUriStrategy,
            'platformAllowlist' => $config->platformAllowlist,
            'remoteProfileAllowlist' => $config->remoteProfileAllowlist,
            'agentAllowlist' => $config->agentAllowlist,
            'embeddedAllowedOrigins' => $config->embeddedAllowedOrigins,
            'embeddedFrameAncestors' => $config->embeddedFrameAncestors,
            'continueUrlTemplate' => $config->continueUrlTemplate,
            'webhookUrlOverride' => $config->webhookUrlOverride,
            'discoveryBudget' => $config->discoveryBudget,
            default => throw new \InvalidArgumentException(\sprintf('Unsupported expected UCP config property "%s".', $property)),
        };
    }
}
