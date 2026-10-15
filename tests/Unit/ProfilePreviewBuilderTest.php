<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Profile\ProfileDraftConfig;
use Swag\AgenticCommerce\Ucp\Profile\ProfilePreviewBuilder;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\ProfileBuilderInterface;

require_once __DIR__.'/RecordingProfileBuilder.php';

/** @internal */
final class ProfilePreviewBuilderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('Shopware\\Core\\Framework\\Mcp\\Controller\\StoreApiMcpServerController', false)) {
            eval('namespace Shopware\\Core\\Framework\\Mcp\\Controller { final class StoreApiMcpServerController {} }');
        }
        $_SERVER['MCP_SERVER'] = '1';
    }

    public static function tearDownAfterClass(): void
    {
        unset($_SERVER['MCP_SERVER']);
    }

    #[Test]
    public function testItDoesNotAdvertiseCurrentVersionAsSupportedVersion(): void
    {
        $profileBuilder = new RecordingProfileBuilder();
        $previewBuilder = new ProfilePreviewBuilder(
            $profileBuilder,
            new ShopwareVersionDetector('6.6.0.0'),
            new ProfileDraftConfig(),
        );

        $preview = $previewBuilder->build(UcpConfig::fromArray(['active' => true]), 'https://merchant.example');

        self::assertSame([], $profileBuilder->lastInput?->supportedVersions);
        self::assertArrayNotHasKey('supported_versions', $preview['ucp']);
    }

    #[Test]
    public function testTheRenderedConfigIsTheDraftForTheDurationOfTheBuild(): void
    {
        $draftConfig = new ProfileDraftConfig();
        $profileBuilder = new class($draftConfig) implements ProfileBuilderInterface {
            public ?UcpConfig $draftDuringBuild = null;

            public function __construct(private readonly ProfileDraftConfig $draftConfig)
            {
            }

            public function build(ProfileBuildInput $input): PlatformProfile
            {
                $this->draftDuringBuild = $this->draftConfig->current();

                return new PlatformProfile($input->version, [], [], []);
            }
        };
        $config = UcpConfig::fromArray(['active' => true, 'enabledCapabilities' => ['cart']]);

        (new ProfilePreviewBuilder($profileBuilder, new ShopwareVersionDetector('6.6.0.0'), $draftConfig))
            ->build($config, 'https://merchant.example');

        self::assertSame($config, $profileBuilder->draftDuringBuild);
        self::assertNull($draftConfig->current(), 'discovery builds after the preview use the stored config again');
    }

    /**
     * @param array<string, mixed>  $payload
     * @param list<string>          $expectedTransports
     * @param array<string, string> $expectedTransportEndpoints
     */
    #[DataProvider('profileTransportProvider')]
    #[Test]
    public function testItPassesLaneAwareTransportsAndEndpointsToProfileBuilder(
        string $shopwareVersion,
        array $payload,
        string $fallbackBaseUri,
        string $expectedBaseUri,
        array $expectedTransports,
        array $expectedTransportEndpoints,
    ): void {
        $profileBuilder = new RecordingProfileBuilder();
        $previewBuilder = new ProfilePreviewBuilder(
            $profileBuilder,
            new ShopwareVersionDetector($shopwareVersion),
            new ProfileDraftConfig(),
        );

        $previewBuilder->build(UcpConfig::fromArray($payload), $fallbackBaseUri, 'sales-channel-id');

        $lastInput = $profileBuilder->lastInput;
        self::assertNotNull($lastInput);

        $actualTransports = array_map(
            static fn (Transport $transport): string => $transport->value,
            $lastInput->transports,
        );

        self::assertSame($expectedBaseUri, $lastInput->baseUri);
        self::assertSame($expectedTransports, $actualTransports);
        self::assertSame($expectedTransportEndpoints, $lastInput->transportEndpoints);
        self::assertSame('sales-channel-id', $lastInput->tenantIdentifier);
    }

    /**
     * @return iterable<string, array{
     *     shopwareVersion: string,
     *     payload: array<string, mixed>,
     *     fallbackBaseUri: string,
     *     expectedBaseUri: string,
     *     expectedTransports: list<string>,
     *     expectedTransportEndpoints: array<string, string>
     * }>
     */
    public static function profileTransportProvider(): iterable
    {
        yield '6.6 omits selected MCP transport' => [
            'shopwareVersion' => '6.6.0.0',
            'payload' => [
                'active' => true,
                'enabledTransports' => ['rest', 'mcp', 'a2a'],
            ],
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedBaseUri' => 'https://merchant.example',
            'expectedTransports' => ['rest', 'a2a'],
            'expectedTransportEndpoints' => [],
        ];

        yield 'trunk includes selected MCP transport and endpoint' => [
            'shopwareVersion' => '6.7.0.0',
            'payload' => [
                'active' => true,
                'profileDomain' => 'https://custom.example/',
                'enabledTransports' => ['rest', 'mcp'],
            ],
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedBaseUri' => 'https://custom.example',
            'expectedTransports' => ['rest', 'mcp'],
            'expectedTransportEndpoints' => [
                'mcp' => 'https://custom.example/ucp/mcp',
            ],
        ];

        yield 'explicit empty transports stay empty in preview input' => [
            'shopwareVersion' => '6.7.0.0',
            'payload' => [
                'active' => true,
                'enabledTransports' => [],
            ],
            'fallbackBaseUri' => 'https://merchant.example',
            'expectedBaseUri' => 'https://merchant.example',
            'expectedTransports' => [],
            'expectedTransportEndpoints' => [],
        ];
    }
}
