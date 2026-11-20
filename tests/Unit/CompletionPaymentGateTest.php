<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentGate;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Symfony\Component\HttpFoundation\Response;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(CompletionPaymentGate::class)]
final class CompletionPaymentGateTest extends TestCase
{
    private const ADVERTISED_HANDLER_ID = 'com.shopware.invoice';

    private const TENANT_IDENTIFIER = 'sales-channel-1';

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function wrongInstrumentCountProvider(): iterable
    {
        yield 'no instrument' => [[], 0];
        yield 'two instruments' => [[self::ADVERTISED_HANDLER_ID, self::ADVERTISED_HANDLER_ID], 2];
    }

    /**
     * @param list<string> $handlerIds
     */
    #[DataProvider('wrongInstrumentCountProvider')]
    #[Test]
    public function testAnInstrumentCountOtherThanOneIsRefusedWithoutBuildingTheProfile(array $handlerIds, int $instrumentCount): void
    {
        $profileBuilderRequests = 0;
        $gate = new CompletionPaymentGate(static function () use (&$profileBuilderRequests): never {
            ++$profileBuilderRequests;

            throw new \LogicException('The profile must not be built for a wrong instrument count.');
        });

        $refusal = $gate->refusal($handlerIds, $this->requestContext());

        self::assertSame(0, $profileBuilderRequests);
        self::assertSame([
            'type' => 'error',
            'content' => \sprintf('Completing a checkout needs exactly one payment instrument, %d given.', $instrumentCount),
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments',
        ], $refusal?->toArray());
    }

    #[Test]
    public function testAHandlerTheProfileDoesNotAdvertiseIsRefused(): void
    {
        $refusal = $this->gateAdvertising(self::ADVERTISED_HANDLER_ID)->refusal(['com.example.deactivated'], $this->requestContext());

        self::assertSame([
            'type' => 'error',
            'content' => 'The payment handler "com.example.deactivated" is not one this business advertises.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments[0].handler_id',
        ], $refusal?->toArray());
    }

    #[Test]
    public function testAProfileAdvertisingNoHandlerRefusesEveryInstrument(): void
    {
        $refusal = $this->gateAdvertising()->refusal([self::ADVERTISED_HANDLER_ID], $this->requestContext());

        self::assertSame('payment_failed', $refusal?->code);
    }

    #[Test]
    public function testOneInstrumentNamingAnAdvertisedHandlerPasses(): void
    {
        self::assertNull($this->gateAdvertising(self::ADVERTISED_HANDLER_ID)->refusal([self::ADVERTISED_HANDLER_ID], $this->requestContext()));
    }

    #[Test]
    public function testTheProfileIsBuiltFromTheRuntimeConfigurationOfTheRequest(): void
    {
        $profileBuilder = new RecordingProfileBuilder();
        $gate = new CompletionPaymentGate(static fn (): RecordingProfileBuilder => $profileBuilder);
        $runtimeConfiguration = new RuntimeConfiguration(
            '2026-08-25',
            'https://shop.example',
            supportedVersions: ['2026-04-08' => 'https://shop.example/ucp/2026-04-08'],
            transports: [Transport::Rest, Transport::Mcp],
            enabledCapabilities: ['dev.ucp.shopping.checkout'],
            tenantIdentifier: 'sales-channel-id',
            transportEndpoints: ['rest' => 'https://shop.example/ucp'],
        );

        $gate->refusal([self::ADVERTISED_HANDLER_ID], new RequestContext('shop.example', runtimeConfiguration: $runtimeConfiguration));

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
        $gate = new CompletionPaymentGate(static function (): never {
            throw new \LogicException('The profile must not be built without a runtime configuration.');
        });

        try {
            $gate->refusal([self::ADVERTISED_HANDLER_ID], new RequestContext('shop.example'));
            self::fail('A missing runtime configuration must not become a refusal the agent can act on.');
        } catch (UcpConfigException $exception) {
            self::assertSame(UcpConfigException::RUNTIME_CONFIGURATION_MISSING, $exception->getErrorCode());
            self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $exception->getStatusCode());
        }
    }

    #[Test]
    public function testAWrongInstrumentCountIsRefusedWithoutRuntimeConfiguration(): void
    {
        $refusal = $this->gateAdvertising(self::ADVERTISED_HANDLER_ID)->refusal([], new RequestContext('shop.example'));

        self::assertSame('$.payment.instruments', $refusal?->path);
    }

    #[Test]
    public function testAWrongInstrumentCountIsLoggedWithTheHandlersAndTheTenant(): void
    {
        $logger = new CollectingLogger();
        $gate = $this->gateAdvertisingWithLogger($logger, self::ADVERTISED_HANDLER_ID);

        $gate->refusal([self::ADVERTISED_HANDLER_ID, 'com.example.card'], $this->requestContext());

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(
            [
                'handler_ids' => [self::ADVERTISED_HANDLER_ID, 'com.example.card'],
                'instrument_count' => 2,
                'tenant_identifier' => self::TENANT_IDENTIFIER,
            ],
            $logger->records[0]['context'],
        );
    }

    #[Test]
    public function testAHandlerTheProfileDoesNotAdvertiseIsLoggedWithTheAdvertisedHandlersAndTheTenant(): void
    {
        $logger = new CollectingLogger();
        $gate = $this->gateAdvertisingWithLogger($logger, self::ADVERTISED_HANDLER_ID);

        $gate->refusal(['com.example.deactivated'], $this->requestContext());

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(
            [
                'handler_id' => 'com.example.deactivated',
                'advertised_handler_ids' => [self::ADVERTISED_HANDLER_ID],
                'tenant_identifier' => self::TENANT_IDENTIFIER,
            ],
            $logger->records[0]['context'],
        );
    }

    #[Test]
    public function testAPassingCompletionLogsNothing(): void
    {
        $logger = new CollectingLogger();
        $gate = $this->gateAdvertisingWithLogger($logger, self::ADVERTISED_HANDLER_ID);

        $gate->refusal([self::ADVERTISED_HANDLER_ID], $this->requestContext());

        self::assertSame([], $logger->records);
    }

    private function gateAdvertising(string ...$handlerIds): CompletionPaymentGate
    {
        $profileBuilder = RecordingProfileBuilder::advertising(...$handlerIds);

        return new CompletionPaymentGate(static fn (): RecordingProfileBuilder => $profileBuilder);
    }

    private function gateAdvertisingWithLogger(LoggerInterface $logger, string ...$handlerIds): CompletionPaymentGate
    {
        $profileBuilder = RecordingProfileBuilder::advertising(...$handlerIds);

        return new CompletionPaymentGate(static fn (): RecordingProfileBuilder => $profileBuilder, $logger);
    }

    private function requestContext(): RequestContext
    {
        return new RequestContext(
            'shop.example',
            runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example', tenantIdentifier: self::TENANT_IDENTIFIER),
        );
    }
}
