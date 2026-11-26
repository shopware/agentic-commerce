<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\CashPayment;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\InvoicePayment;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PrePayment;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Payment\SalesChannelPaymentMethods;
use Symfony\Component\HttpFoundation\Request;

/** @internal */
#[CoversClass(SalesChannelPaymentMethods::class)]
final class SalesChannelPaymentMethodsTest extends TestCase
{
    private const SALES_CHANNEL_ID = '00000000000000000000000000000001';
    private const PAYMENT_METHOD_ID = '00000000000000000000000000000002';
    private const OTHER_PAYMENT_METHOD_ID = '00000000000000000000000000000003';
    private const LATER_INVOICE_PAYMENT_METHOD_ID = '00000000000000000000000000000004';

    /** @var EntityRepository<PaymentMethodCollection>&MockObject */
    private EntityRepository&MockObject $paymentMethodRepository;

    private AbstractPaymentMethodRoute&MockObject $paymentMethodRoute;

    private SalesChannelPaymentMethods $paymentMethods;

    protected function setUp(): void
    {
        $this->paymentMethodRepository = $this->createMock(EntityRepository::class);
        $this->paymentMethodRoute = $this->createMock(AbstractPaymentMethodRoute::class);
        $this->paymentMethods = new SalesChannelPaymentMethods($this->paymentMethodRepository, $this->paymentMethodRoute);
    }

    #[Test]
    public function testTheFirstActiveMethodOfTheSalesChannelThatRunsTheHandlerIsFound(): void
    {
        $searchedCriteria = null;
        $this->paymentMethodRepository->expects(static::once())
            ->method('searchIds')
            ->willReturnCallback(static function (Criteria $criteria, Context $context) use (&$searchedCriteria): IdSearchResult {
                $searchedCriteria = $criteria;

                return new IdSearchResult(1, [self::PAYMENT_METHOD_ID => ['primaryKey' => self::PAYMENT_METHOD_ID, 'data' => ['id' => self::PAYMENT_METHOD_ID]]], $criteria, $context);
            });

        $paymentMethodId = $this->paymentMethods->idForHandler(InvoicePayment::class, self::SALES_CHANNEL_ID);

        self::assertSame(self::PAYMENT_METHOD_ID, $paymentMethodId);
        self::assertInstanceOf(Criteria::class, $searchedCriteria);
        $filterValueByField = [];
        foreach ($searchedCriteria->getFilters() as $filter) {
            self::assertInstanceOf(EqualsFilter::class, $filter);
            $filterValueByField[$filter->getField()] = $filter->getValue();
        }
        self::assertSame([
            'active' => true,
            'salesChannels.id' => self::SALES_CHANNEL_ID,
            'handlerIdentifier' => InvoicePayment::class,
        ], $filterValueByField);
        self::assertEquals([new FieldSorting('position')], $searchedCriteria->getSorting());
        self::assertSame(1, $searchedCriteria->getLimit());
    }

    #[Test]
    public function testASalesChannelWithoutAMethodThatRunsTheHandlerFindsNone(): void
    {
        $this->paymentMethodRepository->method('searchIds')
            ->willReturnCallback(static fn (Criteria $criteria, Context $context): IdSearchResult => new IdSearchResult(0, [], $criteria, $context));

        self::assertNull($this->paymentMethods->idForHandler(InvoicePayment::class, self::SALES_CHANNEL_ID));
    }

    #[Test]
    public function testTheFirstActiveMethodOfEveryHandlerClassIsFoundWithOneSearch(): void
    {
        $searchedCriteria = null;
        $this->paymentMethodRepository->expects(static::once())
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use (&$searchedCriteria): EntitySearchResult {
                $searchedCriteria = $criteria;

                return $this->paymentMethodSearchResult($criteria, [
                    self::PAYMENT_METHOD_ID => InvoicePayment::class,
                    self::OTHER_PAYMENT_METHOD_ID => PrePayment::class,
                    self::LATER_INVOICE_PAYMENT_METHOD_ID => InvoicePayment::class,
                ]);
            });

        $paymentMethodIdByHandlerClass = $this->paymentMethods->idsByHandlerClass([InvoicePayment::class, PrePayment::class, CashPayment::class], self::SALES_CHANNEL_ID);

        self::assertSame([
            InvoicePayment::class => self::PAYMENT_METHOD_ID,
            PrePayment::class => self::OTHER_PAYMENT_METHOD_ID,
        ], $paymentMethodIdByHandlerClass);
        self::assertInstanceOf(Criteria::class, $searchedCriteria);
        $handlerClassesInFilter = [];
        $filterValueByField = [];
        foreach ($searchedCriteria->getFilters() as $filter) {
            if ($filter instanceof EqualsAnyFilter) {
                self::assertSame('handlerIdentifier', $filter->getField());
                $handlerClassesInFilter = $filter->getValue();
                continue;
            }
            self::assertInstanceOf(EqualsFilter::class, $filter);
            $filterValueByField[$filter->getField()] = $filter->getValue();
        }
        self::assertSame([InvoicePayment::class, PrePayment::class, CashPayment::class], $handlerClassesInFilter);
        self::assertSame(['active' => true, 'salesChannels.id' => self::SALES_CHANNEL_ID], $filterValueByField);
        self::assertEquals([new FieldSorting('position')], $searchedCriteria->getSorting());
    }

    #[Test]
    public function testNoHandlerClassesAreFoundWithoutSearching(): void
    {
        $this->paymentMethodRepository->expects(static::never())->method('search');

        self::assertSame([], $this->paymentMethods->idsByHandlerClass([], self::SALES_CHANNEL_ID));
    }

    #[Test]
    public function testAMethodTheRouteOffersTheBuyerIsAvailable(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $this->paymentMethodRoute->expects(static::once())
            ->method('load')
            ->willReturnCallback(function (Request $request, SalesChannelContext $loadContext, Criteria $criteria) use ($context): PaymentMethodRouteResponse {
                self::assertTrue($request->request->getBoolean('onlyAvailable'));
                self::assertSame($context, $loadContext);
                self::assertSame([self::PAYMENT_METHOD_ID], $criteria->getIds());

                return $this->response($criteria, self::PAYMENT_METHOD_ID);
            });

        self::assertTrue($this->paymentMethods->isAvailable(self::PAYMENT_METHOD_ID, $context));
    }

    #[Test]
    public function testAMethodTheRouteFiltersOutIsNotAvailable(): void
    {
        $this->paymentMethodRoute->method('load')
            ->willReturnCallback(fn (Request $request, SalesChannelContext $context, Criteria $criteria): PaymentMethodRouteResponse => $this->response($criteria, self::OTHER_PAYMENT_METHOD_ID));

        self::assertFalse($this->paymentMethods->isAvailable(self::PAYMENT_METHOD_ID, $this->createMock(SalesChannelContext::class)));
    }

    /**
     * @param array<string, string> $handlerClassByPaymentMethodId
     *
     * @return EntitySearchResult<PaymentMethodCollection>
     */
    private function paymentMethodSearchResult(Criteria $criteria, array $handlerClassByPaymentMethodId): EntitySearchResult
    {
        $entities = new PaymentMethodCollection();
        foreach ($handlerClassByPaymentMethodId as $paymentMethodId => $handlerClass) {
            $paymentMethod = new PaymentMethodEntity();
            $paymentMethod->setId($paymentMethodId);
            $paymentMethod->setHandlerIdentifier($handlerClass);
            $entities->add($paymentMethod);
        }

        return new EntitySearchResult('payment_method', $entities->count(), $entities, null, $criteria, Context::createDefaultContext());
    }

    private function response(Criteria $criteria, string ...$paymentMethodIds): PaymentMethodRouteResponse
    {
        $entities = new PaymentMethodCollection();
        foreach ($paymentMethodIds as $paymentMethodId) {
            $paymentMethod = new PaymentMethodEntity();
            $paymentMethod->setId($paymentMethodId);
            $entities->add($paymentMethod);
        }

        return new PaymentMethodRouteResponse(new EntitySearchResult('payment_method', $entities->count(), $entities, null, $criteria, Context::createDefaultContext()));
    }
}
