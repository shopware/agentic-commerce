<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AdvertisedPaymentHandlers;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\CompletionPaymentGate;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(CompletionPaymentGate::class)]
final class CompletionPaymentGateTest extends TestCase
{
    private const ADVERTISED_HANDLER_ID = 'com.shopware.invoice';

    private const TENANT_IDENTIFIER = 'sales-channel-1';

    #[Test]
    public function testAnEntryPointWithoutInstrumentIsRefusedWithoutBuildingTheProfile(): void
    {
        $profileBuilderRequests = 0;
        $gate = new CompletionPaymentGate(new AdvertisedPaymentHandlers(static function () use (&$profileBuilderRequests): never {
            ++$profileBuilderRequests;

            throw new \LogicException('The profile must not be built without an instrument.');
        }));

        $refusal = $gate->refusal(null, $this->requestContext());

        self::assertSame(0, $profileBuilderRequests);
        self::assertSame([
            'type' => 'error',
            'content' => 'Completing a checkout needs a payment instrument, and this entry point carries none.',
            'severity' => 'recoverable',
            'code' => 'payment_failed',
            'path' => '$.payment.instruments',
        ], $refusal?->toArray());
    }

    #[Test]
    public function testAHandlerTheProfileDoesNotAdvertiseIsRefused(): void
    {
        $refusal = $this->gateAdvertising(self::ADVERTISED_HANDLER_ID)->refusal('com.example.deactivated', $this->requestContext());

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
        $refusal = $this->gateAdvertising()->refusal(self::ADVERTISED_HANDLER_ID, $this->requestContext());

        self::assertSame('payment_failed', $refusal?->code);
    }

    #[Test]
    public function testAnInstrumentNamingAnAdvertisedHandlerPasses(): void
    {
        self::assertNull($this->gateAdvertising(self::ADVERTISED_HANDLER_ID)->refusal(self::ADVERTISED_HANDLER_ID, $this->requestContext()));
    }

    #[Test]
    public function testARequestWithoutRuntimeConfigurationIsAServerFault(): void
    {
        $gate = new CompletionPaymentGate(new AdvertisedPaymentHandlers(static function (): never {
            throw new \LogicException('The profile must not be built without a runtime configuration.');
        }));

        $this->expectException(UcpConfigException::class);

        $gate->refusal(self::ADVERTISED_HANDLER_ID, new RequestContext('shop.example'));
    }

    #[Test]
    public function testAHandlerTheProfileDoesNotAdvertiseIsLoggedWithTheAdvertisedHandlersAndTheTenant(): void
    {
        $logger = new CollectingLogger();
        $gate = $this->gateAdvertisingWithLogger($logger, self::ADVERTISED_HANDLER_ID);

        $gate->refusal('com.example.deactivated', $this->requestContext());

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
    public function testAPassingInstrumentLogsNothing(): void
    {
        $logger = new CollectingLogger();
        $gate = $this->gateAdvertisingWithLogger($logger, self::ADVERTISED_HANDLER_ID);

        $gate->refusal(self::ADVERTISED_HANDLER_ID, $this->requestContext());

        self::assertSame([], $logger->records);
    }

    private function gateAdvertising(string ...$handlerIds): CompletionPaymentGate
    {
        return new CompletionPaymentGate($this->advertisedHandlers(...$handlerIds));
    }

    private function gateAdvertisingWithLogger(LoggerInterface $logger, string ...$handlerIds): CompletionPaymentGate
    {
        return new CompletionPaymentGate($this->advertisedHandlers(...$handlerIds), $logger);
    }

    private function advertisedHandlers(string ...$handlerIds): AdvertisedPaymentHandlers
    {
        $profileBuilder = RecordingProfileBuilder::advertising(...$handlerIds);

        return new AdvertisedPaymentHandlers(static fn (): RecordingProfileBuilder => $profileBuilder);
    }

    private function requestContext(): RequestContext
    {
        return new RequestContext(
            'shop.example',
            runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example', tenantIdentifier: self::TENANT_IDENTIFIER),
        );
    }
}
