<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\CashPayment;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\InvoicePayment;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Swag\AgenticCommerce\Ucp\Payment\ShopwareOfflinePaymentHandler;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(ShopwareOfflinePaymentHandler::class)]
final class ShopwareOfflinePaymentHandlerTest extends TestCase
{
    private const SALES_CHANNEL_ID = '00000000000000000000000000000001';
    private const INVOICE_PAYMENT_METHOD_ID = '00000000000000000000000000000002';
    private const AGENT_CHOSEN_PAYMENT_METHOD_ID = '00000000000000000000000000000003';

    private EntityRepository&MockObject $paymentMethodRepository;

    protected function setUp(): void
    {
        $this->paymentMethodRepository = $this->createMock(EntityRepository::class);
    }

    #[Test]
    public function testTheHandlerNamesItselfByItsId(): void
    {
        self::assertSame(ShopwareOfflinePaymentHandler::INVOICE_ID, $this->invoiceHandler()->id());
    }

    #[Test]
    public function testTheInvoiceHandlerIsDescribedWithItsSpecAndInstrumentSchema(): void
    {
        $descriptor = $this->invoiceHandler()->describe(new RequestContext('shop.example'));

        self::assertSame('com.shopware.invoice', $descriptor->id);
        self::assertSame('com.shopware.invoice', $descriptor->name);
        self::assertSame(UcpProtocol::VERSION, $descriptor->version);
        self::assertSame('https://developer.shopware.com/ucp/payment-handlers/invoice', $descriptor->specUrl);
        self::assertSame('https://ucp.dev/schemas/payments/delegate-payment.json', $descriptor->configSchema);
        self::assertSame(['https://ucp.dev/schemas/shopping/types/invoice_payment_instrument.json'], $descriptor->instrumentSchemas);
        self::assertFalse($descriptor->config['tokenization']);
    }

    #[Test]
    public function testTheCashOnDeliveryHandlerSpellsItsIdWithDashesInTheSpecUrlAndUnderscoresInTheSchema(): void
    {
        $handler = new ShopwareOfflinePaymentHandler(ShopwareOfflinePaymentHandler::CASH_ON_DELIVERY_ID, CashPayment::class, $this->paymentMethods());

        $descriptor = $handler->describe(new RequestContext('shop.example'));

        self::assertSame('com.shopware.cash_on_delivery', $descriptor->id);
        self::assertSame('https://developer.shopware.com/ucp/payment-handlers/cash-on-delivery', $descriptor->specUrl);
        self::assertSame(['https://ucp.dev/schemas/shopping/types/cash_on_delivery_payment_instrument.json'], $descriptor->instrumentSchemas);
    }

    #[Test]
    public function testThePaymentMethodIsResolvedOnTheServerAndTheAgentsCredentialIsIgnored(): void
    {
        $this->paymentMethodRepository->expects(static::once())
            ->method('searchIds')
            ->willReturnCallback(fn (Criteria $criteria, Context $context): IdSearchResult => $this->idSearchResult([self::INVOICE_PAYMENT_METHOD_ID], $criteria, $context));

        $instrument = new PaymentInstrument('invoice', ShopwareOfflinePaymentHandler::INVOICE_ID, [
            'payment_method_id' => self::AGENT_CHOSEN_PAYMENT_METHOD_ID,
            'paymentMethodId' => self::AGENT_CHOSEN_PAYMENT_METHOD_ID,
        ]);

        self::assertSame(
            ['paymentMethodId' => self::INVOICE_PAYMENT_METHOD_ID, 'token' => ''],
            $this->invoiceHandler()->prepareInstrument($instrument, $this->requestContext(self::SALES_CHANNEL_ID)),
        );
    }

    #[Test]
    public function testASalesChannelWithoutAMatchingPaymentMethodResolvesToAnEmptyId(): void
    {
        $this->paymentMethodRepository->method('searchIds')
            ->willReturnCallback(fn (Criteria $criteria, Context $context): IdSearchResult => $this->idSearchResult([], $criteria, $context));

        $prepared = $this->invoiceHandler()->prepareInstrument(
            new PaymentInstrument('invoice', ShopwareOfflinePaymentHandler::INVOICE_ID),
            $this->requestContext(self::SALES_CHANNEL_ID),
        );

        self::assertSame('', $prepared['paymentMethodId']);
    }

    #[Test]
    public function testARequestWithoutASalesChannelResolvesToAnEmptyIdWithoutSearching(): void
    {
        $this->paymentMethodRepository->expects(static::never())->method('searchIds');

        $prepared = $this->invoiceHandler()->prepareInstrument(
            new PaymentInstrument('invoice', ShopwareOfflinePaymentHandler::INVOICE_ID),
            $this->requestContext(null),
        );

        self::assertSame('', $prepared['paymentMethodId']);
    }

    #[Test]
    public function testThePaymentMethodIsLookedUpByTheCoreHandlerClassOfTheHandler(): void
    {
        $searched = null;
        $this->paymentMethodRepository->method('searchIds')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use (&$searched): IdSearchResult {
                $searched = $criteria;

                return $this->idSearchResult([], $criteria, $context);
            });

        $this->invoiceHandler()->paymentMethodId(self::SALES_CHANNEL_ID);

        self::assertInstanceOf(Criteria::class, $searched);
        $handlerClasses = [];
        foreach ($searched->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter && 'handlerIdentifier' === $filter->getField()) {
                $handlerClasses[] = $filter->getValue();
            }
        }
        self::assertSame([InvoicePayment::class], $handlerClasses);
    }

    #[Test]
    public function testTheHandlerDoesNotTokenize(): void
    {
        $handler = $this->invoiceHandler();

        self::assertFalse($handler->supportsTokenization());
        self::assertNull($handler->tokenize(new PaymentInstrument('invoice', $handler->id()), new RequestContext('shop.example')));
    }

    private function invoiceHandler(): ShopwareOfflinePaymentHandler
    {
        return new ShopwareOfflinePaymentHandler(ShopwareOfflinePaymentHandler::INVOICE_ID, InvoicePayment::class, $this->paymentMethods());
    }

    private function paymentMethods(): SalesChannelPaymentMethods
    {
        return new SalesChannelPaymentMethods($this->paymentMethodRepository, $this->createMock(AbstractPaymentMethodRoute::class));
    }

    private function requestContext(?string $tenantIdentifier): RequestContext
    {
        return new RequestContext(
            'shop.example',
            runtimeConfiguration: new RuntimeConfiguration('2026-08-25', 'https://shop.example', tenantIdentifier: $tenantIdentifier),
        );
    }

    /**
     * @param list<string> $ids
     */
    private function idSearchResult(array $ids, Criteria $criteria, Context $context): IdSearchResult
    {
        $data = [];
        foreach ($ids as $id) {
            $data[$id] = ['primaryKey' => $id, 'data' => ['id' => $id]];
        }

        return new IdSearchResult(\count($data), $data, $criteria, $context);
    }
}
