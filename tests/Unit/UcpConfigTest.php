<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
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
    public function testItNormalizesSecuritySensitiveValues(): void
    {
        $config = UcpConfig::fromArray([
            'active' => true,
            'profileDomain' => ' HTTPS://Merchant.Example./ucp/ ',
            'enabledTransports' => ['rest', 'rest'],
            'platformAllowlist' => ['Merchant.Example.', 'merchant.example'],
            'remoteProfileAllowlist' => ['Platform.Example', 'platform.example.'],
            'agentAllowlist' => ['Agent.Example'],
            'embeddedAllowedOrigins' => ['HTTPS://Assistant.Example:8443/'],
            'embeddedFrameAncestors' => ["'self'", 'HTTPS://Frame.Example/'],
            'continueUrlTemplate' => 'HTTPS://Merchant.Example/checkout/confirm?checkoutId={checkoutId}',
            'webhookUrlOverride' => 'HTTPS://Agent.Example/ucp',
            'signaturePolicy' => 'strict',
        ]);

        self::assertTrue($config->active);
        self::assertSame('https://merchant.example/ucp', $config->resolveBaseUri('https://fallback.example'));
        self::assertSame('https://merchant.example/ucp/', $config->profileDomain);
        self::assertSame(['rest'], $config->enabledTransports);
        self::assertSame(['rest'], array_map(static fn ($transport): string => $transport->value, $config->runtimeTransports()));
        self::assertSame(['merchant.example'], $config->platformAllowlist);
        self::assertSame(['platform.example'], $config->remoteProfileAllowlist);
        self::assertSame(['agent.example'], $config->agentAllowlist);
        self::assertSame(['https://assistant.example:8443'], $config->embeddedAllowedOrigins);
        self::assertSame(["'self'", 'https://frame.example'], $config->embeddedFrameAncestors);
        self::assertSame('https://merchant.example/checkout/confirm?checkoutId={checkoutId}', $config->continueUrlTemplate);
        self::assertSame('https://agent.example/ucp', $config->webhookUrlOverride);
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
        self::assertSame(50, $config->catalogResultLimit);
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
    public function testItNormalizesDuplicateCapabilitiesAndTransportsBeforeStorage(): void
    {
        $config = UcpConfig::fromArray([
            'enabledCapabilities' => [
                UcpCapabilityCatalog::CONFIG_CATALOG,
                UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION,
                UcpCapabilityCatalog::CONFIG_CATALOG,
            ],
            'enabledTransports' => ['rest', 'a2a', 'rest'],
        ]);

        self::assertSame([
            UcpCapabilityCatalog::CONFIG_CATALOG,
            UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION,
        ], $config->enabledCapabilities);
        self::assertSame(['rest', 'a2a'], $config->enabledTransports);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidConfigProvider')]
    #[Test]
    public function testItRejectsInvalidConfigValues(array $payload, UcpConfigException $exception): void
    {
        $this->expectExceptionObject($exception);

        UcpConfig::fromArray($payload);
    }

    #[Test]
    public function testItRejectsBrokenJsonConfig(): void
    {
        $this->expectExceptionObject(UcpConfigException::invalidJsonPayload());

        UcpConfig::fromJson('{"active": true');
    }

    #[Test]
    public function testItRejectsJsonConfigThatIsNotAnObject(): void
    {
        $this->expectExceptionObject(UcpConfigException::invalidValue('$', 'must be a JSON object'));

        UcpConfig::fromJson('[]');
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
                'profileDomain' => null,
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

        yield 'duplicate config values are normalized' => [
            'payload' => [
                'active' => true,
                'enabledCapabilities' => ['catalog', 'payment_tokenization', 'catalog'],
                'enabledTransports' => ['rest', 'a2a', 'rest'],
                'signaturePolicy' => 'log',
                'idempotencyRequired' => false,
            ],
            'storeApiMcpAvailable' => false,
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedConfig' => [
                'enabledCapabilities' => ['catalog', 'payment_tokenization'],
                'enabledTransports' => ['rest', 'a2a'],
                'signaturePolicy' => 'log',
                'idempotencyRequired' => false,
            ],
            'expectedRuntime' => [
                'baseUri' => 'https://merchant.example',
                'signaturePolicy' => 'log',
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

        yield 'no profile domain falls back to the channel base uri' => [
            'payload' => [
                'active' => true,
                'enabledCapabilities' => $defaultCapabilities,
                'enabledTransports' => ['rest'],
            ],
            'storeApiMcpAvailable' => false,
            'fallbackBaseUri' => 'https://merchant.example/domain',
            'expectedConfig' => [
                'profileDomain' => null,
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

        yield 'profile domain and MCP endpoint are used when available' => [
            'payload' => [
                'active' => true,
                'profileDomain' => 'https://custom.example/',
                'enabledTransports' => ['rest', 'mcp'],
                'signaturePolicy' => 'log',
            ],
            'storeApiMcpAvailable' => true,
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedConfig' => [
                'profileDomain' => 'https://custom.example/',
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
                'webhookUrlOverride' => 'https://agent.example/ucp',
                'discoveryBudget' => 5,
                'catalogResultLimit' => 25,
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
                'webhookUrlOverride' => 'https://agent.example/ucp',
                'discoveryBudget' => 5,
                'catalogResultLimit' => 25,
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

    /**
     * @return iterable<string, array{payload: array<string, mixed>, exception: UcpConfigException}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'profile domain must be absolute URL' => [
            'payload' => ['profileDomain' => '/profile'],
            'exception' => UcpConfigException::invalidValue('$.profileDomain', 'must be an absolute http(s) URL'),
        ];

        yield 'unsupported UCP version' => [
            'payload' => ['ucpVersion' => '0.0.0'],
            'exception' => UcpConfigException::invalidValue('$.ucpVersion', \sprintf('must be "%s"', UcpProtocol::VERSION)),
        ];

        yield 'continue URL must be absolute' => [
            'payload' => ['continueUrlTemplate' => '/checkout/{checkoutId}'],
            'exception' => UcpConfigException::invalidValue('$.continueUrlTemplate', 'must be an absolute http(s) URL'),
        ];

        yield 'continue URL rejects unknown placeholders' => [
            'payload' => ['continueUrlTemplate' => 'https://merchant.example/checkout/{unknown}'],
            'exception' => UcpConfigException::invalidValue('$.continueUrlTemplate', 'unsupported placeholder "{unknown}"'),
        ];

        yield 'host allowlists reject URLs' => [
            'payload' => ['platformAllowlist' => ['https://merchant.example']],
            'exception' => UcpConfigException::invalidValue('$.platformAllowlist[0]', 'must be a valid host'),
        ];

        yield 'embedded origins reject paths' => [
            'payload' => ['embeddedAllowedOrigins' => ['https://assistant.example/app']],
            'exception' => UcpConfigException::invalidValue('$.embeddedAllowedOrigins[0]', 'must not contain a path'),
        ];

        yield 'frame ancestors reject broad wildcard' => [
            'payload' => ['embeddedFrameAncestors' => ['*']],
            'exception' => UcpConfigException::invalidValue('$.embeddedFrameAncestors[0]', 'must be an absolute origin'),
        ];

        yield 'frame ancestors reject none with other sources' => [
            'payload' => ['embeddedFrameAncestors' => ["'none'", 'https://assistant.example']],
            'exception' => UcpConfigException::invalidValue('$.embeddedFrameAncestors', '"none" cannot be combined with other frame ancestors'),
        ];

        yield 'webhook override host must be configured allowlist host' => [
            'payload' => [
                'agentAllowlist' => ['agent.example'],
                'webhookUrlOverride' => 'https://evil.example/webhook',
            ],
            'exception' => UcpConfigException::invalidValue('$.webhookUrlOverride', 'host must be listed in agentAllowlist or platformAllowlist'),
        ];

        yield 'unknown signature policy' => [
            'payload' => ['signaturePolicy' => 'invalid'],
            'exception' => UcpConfigException::invalidValue('$.signaturePolicy', 'must be a supported signature policy'),
        ];

        yield 'unknown capability' => [
            'payload' => ['enabledCapabilities' => ['catalog', 'unknown']],
            'exception' => UcpConfigException::invalidValue('$.enabledCapabilities', 'unsupported capability "unknown"'),
        ];

        yield 'unknown transport' => [
            'payload' => ['enabledTransports' => ['rest', 'invalid']],
            'exception' => UcpConfigException::invalidValue('$.enabledTransports', 'unsupported transport "invalid"'),
        ];

        yield 'boolean values must be parseable' => [
            'payload' => ['active' => 'sometimes'],
            'exception' => UcpConfigException::invalidValue('$.active', 'must be a boolean'),
        ];

        yield 'discovery budget must be non-negative' => [
            'payload' => ['discoveryBudget' => -1],
            'exception' => UcpConfigException::invalidValue('$.discoveryBudget', 'must be a non-negative integer'),
        ];

        yield 'catalog result limit must be positive' => [
            'payload' => ['catalogResultLimit' => 0],
            'exception' => UcpConfigException::invalidValue('$.catalogResultLimit', 'must be a positive integer'),
        ];

        yield 'unknown config keys are rejected' => [
            'payload' => ['active' => true, 'unexpected' => true],
            'exception' => UcpConfigException::invalidValue('$.unexpected', 'must be a supported config field'),
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
            'profileDomain' => $config->profileDomain,
            'platformAllowlist' => $config->platformAllowlist,
            'remoteProfileAllowlist' => $config->remoteProfileAllowlist,
            'agentAllowlist' => $config->agentAllowlist,
            'embeddedAllowedOrigins' => $config->embeddedAllowedOrigins,
            'embeddedFrameAncestors' => $config->embeddedFrameAncestors,
            'continueUrlTemplate' => $config->continueUrlTemplate,
            'webhookUrlOverride' => $config->webhookUrlOverride,
            'discoveryBudget' => $config->discoveryBudget,
            'catalogResultLimit' => $config->catalogResultLimit,
            default => throw new \InvalidArgumentException(\sprintf('Unsupported expected UCP config property "%s".', $property)),
        };
    }
}
