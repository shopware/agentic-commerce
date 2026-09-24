<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\PaymentInstrumentResolver;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/**
 * Pins the documented seam: a UCP payment instrument resolves, through the
 * handler that published it, to the concrete payment method a provider can
 * charge. This was the "intended path" of docs/completion-payment.md that no
 * release ever wired up, so a payment integration had to reimplement handler
 * lookup + prepareInstrument for itself.
 *
 * The resolver deliberately does NOT decide who charges or how. It only turns
 * the instrument into the (paymentMethodId, token) pair the handler declares;
 * switching the order or settling stays with the caller's applier.
 */
#[CoversClass(PaymentInstrumentResolver::class)]
final class PaymentInstrumentResolverTest extends TestCase
{
    #[Test]
    public function itResolvesTheInstrumentThroughItsHandler(): void
    {
        $handler = $this->createMock(PaymentHandlerInterface::class);
        $handler->method('prepareInstrument')->willReturn([
            'paymentMethodId' => 'pm-nano',
            'token' => '',
        ]);

        $registry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $registry->method('find')->with('com.nano.xno')->willReturn($handler);

        $resolved = (new PaymentInstrumentResolver($registry))->prepare(
            new PaymentInstrument('crypto', 'com.nano.xno', ['network' => 'nano:mainnet']),
            new RequestContext('shop.example'),
        );

        self::assertSame('pm-nano', $resolved['paymentMethodId']);
    }

    #[Test]
    public function itPassesTheInstrumentAndContextThroughToPrepareInstrument(): void
    {
        $instrument = new PaymentInstrument('crypto', 'com.nano.xno', ['network' => 'nano:mainnet']);
        $context = new RequestContext('shop.example');

        $handler = $this->createMock(PaymentHandlerInterface::class);
        $handler->expects(self::once())
            ->method('prepareInstrument')
            ->with($instrument, $context)
            ->willReturn(['paymentMethodId' => 'pm-nano', 'token' => '']);
        $handler->method('supportsTokenization')->willReturn(false);

        $registry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $registry->method('find')->with('com.nano.xno')->willReturn($handler);

        (new PaymentInstrumentResolver($registry))->prepare($instrument, $context);
    }

    #[Test]
    public function itRefusesWhenTheHandlerIsNotPublished(): void
    {
        $registry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $registry->method('find')->willReturn(null);

        $this->expectException(ValidationException::class);

        (new PaymentInstrumentResolver($registry))->prepare(
            new PaymentInstrument('crypto', 'com.never.published'),
            new RequestContext('shop.example'),
        );
    }
}
