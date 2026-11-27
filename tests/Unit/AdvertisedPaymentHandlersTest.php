<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AdvertisedPaymentHandlers;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Symfony\Component\HttpFoundation\Response;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(AdvertisedPaymentHandlers::class)]
final class AdvertisedPaymentHandlersTest extends TestCase
{
    #[Test]
    public function testTheIdsOfEveryHandlerOfTheProfileAreReturned(): void
    {
        $profileBuilder = RecordingProfileBuilder::advertising('com.shopware.invoice', 'com.shopware.prepayment');
        $advertisedHandlers = new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $profileBuilder);

        self::assertSame(
            ['com.shopware.invoice', 'com.shopware.prepayment'],
            $advertisedHandlers->ids($this->requestContext()),
        );
    }

    #[Test]
    public function testAProfileWithoutHandlersAdvertisesNone(): void
    {
        $profileBuilder = RecordingProfileBuilder::advertising();
        $advertisedHandlers = new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $profileBuilder);

        self::assertSame([], $advertisedHandlers->ids($this->requestContext()));
    }

    #[Test]
    public function testTheProfileIsBuiltFromTheRuntimeConfigurationOfTheRequest(): void
    {
        $profileBuilder = new RecordingProfileBuilder();
        $advertisedHandlers = new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $profileBuilder);
        $runtimeConfiguration = new RuntimeConfiguration(
            '2026-08-25',
            'https://shop.example',
            supportedVersions: ['2026-04-08' => 'https://shop.example/ucp/2026-04-08'],
            transports: [Transport::Rest, Transport::Mcp],
            enabledCapabilities: ['dev.ucp.shopping.checkout'],
            tenantIdentifier: 'sales-channel-id',
            transportEndpoints: ['rest' => 'https://shop.example/ucp'],
        );

        $advertisedHandlers->ids(new RequestContext('shop.example', runtimeConfiguration: $runtimeConfiguration));

        $profileBuildInput = $profileBuilder->lastInput;
        self::assertNotNull($profileBuildInput);
        self::assertSame($runtimeConfiguration->version, $profileBuildInput->version);
        self::assertSame($runtimeConfiguration->baseUri, $profileBuildInput->baseUri);
        self::assertSame($runtimeConfiguration->transports, $profileBuildInput->transports);
        self::assertSame($runtimeConfiguration->supportedVersions, $profileBuildInput->supportedVersions);
        self::assertSame($runtimeConfiguration->transportEndpoints, $profileBuildInput->transportEndpoints);
        self::assertSame($runtimeConfiguration->tenantIdentifier, $profileBuildInput->tenantIdentifier);
        self::assertSame($runtimeConfiguration->enabledCapabilities, $profileBuildInput->enabledCapabilities);
    }

    #[Test]
    public function testARequestWithoutRuntimeConfigurationIsAServerFaultWithoutBuildingTheProfile(): void
    {
        $advertisedHandlers = new AdvertisedPaymentHandlers(static function (): never {
            throw new \LogicException('The profile must not be built without a runtime configuration.');
        });

        try {
            $advertisedHandlers->ids(new RequestContext('shop.example'));
            self::fail('A missing runtime configuration must not become an empty list of handlers.');
        } catch (UcpConfigException $exception) {
            self::assertSame(UcpConfigException::RUNTIME_CONFIGURATION_MISSING, $exception->getErrorCode());
            self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $exception->getStatusCode());
        }
    }

    private function requestContext(): RequestContext
    {
        return new RequestContext(
            'shop.example',
            runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example'),
        );
    }
}
