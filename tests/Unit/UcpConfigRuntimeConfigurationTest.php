<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;

/** @internal */
final class UcpConfigRuntimeConfigurationTest extends TestCase
{
    #[Test]
    public function testItBuildsRuntimeConfigurationFromFallbackBaseUri(): void
    {
        $config = UcpConfig::fromArray([
            'active' => true,
            'ucpVersion' => UcpProtocol::VERSION,
            'idempotencyRequired' => true,
        ]);

        $runtimeConfiguration = $config->toRuntimeConfiguration('https://merchant.example', 'sales-channel-id');

        self::assertSame(UcpProtocol::VERSION, $runtimeConfiguration->version);
        self::assertSame('https://merchant.example', $runtimeConfiguration->baseUri);
        self::assertSame(SignaturePolicy::Strict, $runtimeConfiguration->signaturePolicy);
        self::assertTrue($runtimeConfiguration->idempotencyRequired);
        self::assertSame('sales-channel-id', $runtimeConfiguration->tenantIdentifier);
        self::assertSame([], $runtimeConfiguration->supportedVersions);
        self::assertSame([Transport::Rest], $runtimeConfiguration->transports);
        self::assertSame(UcpCapabilityCatalog::descriptorNamesForConfigKeys(UcpCapabilityCatalog::defaultConfigKeys()), $runtimeConfiguration->enabledCapabilities);
    }

    /**
     * The SDK's request-context factory reads development mode from the resolved runtime
     * configuration. It used to reach only the URL-safety validator, so the shop could never act
     * as its own agent locally no matter what the environment said.
     */
    #[Test]
    public function testItCarriesTheDevelopmentModeFlagIntoTheRuntimeConfiguration(): void
    {
        $config = UcpConfig::fromArray(['active' => true]);

        self::assertFalse($config->toRuntimeConfiguration('https://merchant.example')->profileFetchingDevelopmentMode);
        self::assertTrue($config->toRuntimeConfiguration('https://merchant.example', null, false, true)->profileFetchingDevelopmentMode);
    }

    #[Test]
    public function testItBuildsRuntimeConfigurationForStoreApiMcp(): void
    {
        $config = UcpConfig::fromArray([
            'active' => true,
            'enabledTransports' => ['rest', 'mcp'],
        ]);

        $runtimeConfiguration = $config->toRuntimeConfiguration('https://merchant.example', 'sales-channel-id', true);

        self::assertSame([Transport::Rest, Transport::Mcp], $runtimeConfiguration->transports);
        self::assertSame([
            'mcp' => 'https://merchant.example/ucp/mcp',
        ], $runtimeConfiguration->transportEndpoints);
    }
}
