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
    /** @test */
    #[Test]
    public function itBuildsRuntimeConfigurationFromFallbackBaseUri(): void
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
        self::assertSame([Transport::Rest], $runtimeConfiguration->transports);
        self::assertSame(UcpCapabilityCatalog::descriptorNamesForConfigKeys(UcpCapabilityCatalog::defaultConfigKeys()), $runtimeConfiguration->enabledCapabilities);
    }

    /** @test */
    #[Test]
    public function itBuildsRuntimeConfigurationForStoreApiMcp(): void
    {
        $config = UcpConfig::fromArray([
            'active' => true,
            'enabledTransports' => ['rest', 'mcp'],
        ]);

        $runtimeConfiguration = $config->toRuntimeConfiguration('https://merchant.example', 'sales-channel-id', true);

        self::assertSame([Transport::Rest, Transport::Mcp], $runtimeConfiguration->transports);
        self::assertSame([
            'mcp' => 'https://merchant.example/store-api/_mcp',
        ], $runtimeConfiguration->transportEndpoints);
    }
}
