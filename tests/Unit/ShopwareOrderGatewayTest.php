<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\OrderRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareOrderGateway;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
#[CoversClass(ShopwareOrderGateway::class)]
final class ShopwareOrderGatewayTest extends TestCase
{
    #[Test]
    public function testItDelegatesPublicOrderReadsToStoreApiOrderRouteWithIncomingContextToken(): void
    {
        $salesChannelId = '22222222222222222222222222222222';
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getCustomer')->willReturn(new CustomerEntity());
        $contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $contextService->expects(static::once())
            ->method('get')
            ->willReturnCallback(static function (SalesChannelContextServiceParameters $parameters) use ($salesChannelId, $salesChannelContext): SalesChannelContext {
                self::assertSame($salesChannelId, $parameters->getSalesChannelId());
                self::assertSame('customer-context-token', $parameters->getToken());
                self::assertSame('customer-id', $parameters->getCustomerId());

                return $salesChannelContext;
            });

        $contextPersister = $this->createMock(SalesChannelContextPersister::class);
        $contextPersister->expects(static::once())
            ->method('load')
            ->with('customer-context-token', $salesChannelId)
            ->willReturn(['customerId' => 'customer-id']);

        $order = new OrderEntity();
        $order->setId('99999999999999999999999999999999');

        $orderRoute = $this->createMock(AbstractOrderRoute::class);
        $orderRoute->expects(static::once())
            ->method('load')
            ->willReturnCallback(static function (
                Request $_request,
                SalesChannelContext $context,
                Criteria $criteria,
            ) use ($salesChannelContext, $order): OrderRouteResponse {
                self::assertSame($salesChannelContext, $context);
                self::assertSame(['99999999999999999999999999999999'], $criteria->getIds());
                self::assertArrayHasKey('orderCustomer', $criteria->getAssociations());
                self::assertArrayHasKey('currency', $criteria->getAssociations());
                self::assertArrayHasKey('billingAddress', $criteria->getAssociations());
                self::assertArrayHasKey('lineItems', $criteria->getAssociations());
                self::assertArrayHasKey('stateMachineState', $criteria->getAssociations());

                $orders = new OrderCollection([$order]);

                return new OrderRouteResponse(new EntitySearchResult(
                    'order',
                    $orders->count(),
                    $orders,
                    null,
                    $criteria,
                    Context::createDefaultContext(),
                ));
            });

        $gateway = new ShopwareOrderGateway(
            new SalesChannelContextResolver($this->domainResolver($salesChannelId), $contextService, $contextPersister),
            $this->createMock(AbstractCartOrderRoute::class),
            $orderRoute,
            new CheckoutSessionStore($contextPersister),
            $this->requestStackWithIncomingContextToken('customer-context-token'),
        );

        self::assertSame($order, $gateway->getOrder(
            '99999999999999999999999999999999',
            new RequestContext('shop.example', [
                PlatformRequest::HEADER_CONTEXT_TOKEN => 'storefront-session-token',
            ]),
        ));
    }

    #[Test]
    public function testItDelegatesGuestOrderReadsToStoreApiOrderRouteWithCheckoutMetadata(): void
    {
        $salesChannelId = '22222222222222222222222222222222';
        $orderId = '99999999999999999999999999999999';

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getCustomer')->willReturn(null);
        $salesChannelContext->method('getSalesChannelId')->willReturn($salesChannelId);

        $contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $contextService->expects(static::once())
            ->method('get')
            ->willReturnCallback(static function (SalesChannelContextServiceParameters $parameters) use ($salesChannelId, $salesChannelContext): SalesChannelContext {
                self::assertSame($salesChannelId, $parameters->getSalesChannelId());
                self::assertSame('guest-context-token', $parameters->getToken());
                self::assertSame('customer-id', $parameters->getCustomerId());

                return $salesChannelContext;
            });

        $contextPersister = $this->createMock(SalesChannelContextPersister::class);
        $contextLoads = 0;
        $contextPersister->expects(static::exactly(2))
            ->method('load')
            ->willReturnCallback(static function (string $token, string $requestedSalesChannelId, ?string $customerId = null) use (&$contextLoads, $salesChannelId, $orderId): array {
                self::assertSame('guest-context-token', $token);
                self::assertSame($salesChannelId, $requestedSalesChannelId);
                self::assertNull($customerId);

                ++$contextLoads;
                if (1 === $contextLoads) {
                    return ['customerId' => 'customer-id'];
                }

                return [
                    'swagAgenticCommerce' => [
                        'ucpCheckout' => [
                            'status' => 'completed',
                            'orderId' => $orderId,
                            'orderDeepLinkCode' => 'deep-link-code',
                            'shopwareContextToken' => 'guest-context-token',
                            'buyer' => ['email' => 'ada@example.com'],
                            'guestAddress' => [
                                'street' => 'Main Street 1',
                                'zipcode' => '12345',
                                'city' => 'Berlin',
                            ],
                        ],
                    ],
                ];
            });

        $order = new OrderEntity();
        $order->setId($orderId);

        $orderRoute = $this->createMock(AbstractOrderRoute::class);
        $orderRoute->expects(static::once())
            ->method('load')
            ->willReturnCallback(static function (
                Request $request,
                SalesChannelContext $context,
                Criteria $criteria,
            ) use ($salesChannelContext, $orderId, $order): OrderRouteResponse {
                self::assertSame($salesChannelContext, $context);
                self::assertSame([$orderId], $criteria->getIds());
                self::assertSame('ada@example.com', $request->query->get('email'));
                self::assertSame('12345', $request->query->get('zipcode'));

                $deepLinkFilters = array_filter(
                    $criteria->getFilters(),
                    static fn (object $filter): bool => $filter instanceof EqualsFilter
                        && 'order.deepLinkCode' === $filter->getField()
                        && 'deep-link-code' === $filter->getValue(),
                );
                self::assertCount(1, $deepLinkFilters);

                $orders = new OrderCollection([$order]);

                return new OrderRouteResponse(new EntitySearchResult(
                    'order',
                    $orders->count(),
                    $orders,
                    null,
                    $criteria,
                    Context::createDefaultContext(),
                ));
            });

        $gateway = new ShopwareOrderGateway(
            new SalesChannelContextResolver($this->domainResolver($salesChannelId), $contextService, $contextPersister),
            $this->createMock(AbstractCartOrderRoute::class),
            $orderRoute,
            new CheckoutSessionStore($contextPersister),
            new RequestStack(),
        );

        self::assertSame($order, $gateway->getOrder(
            $orderId,
            new RequestContext('shop.example', [
                PlatformRequest::HEADER_CONTEXT_TOKEN => 'guest-context-token',
            ]),
        ));
    }

    #[Test]
    public function testItRequiresIncomingContextTokenForPublicOrderReads(): void
    {
        $gateway = new ShopwareOrderGateway(
            $this->uninitialized(SalesChannelContextResolver::class),
            $this->createMock(AbstractCartOrderRoute::class),
            $this->createMock(AbstractOrderRoute::class),
            $this->uninitialized(CheckoutSessionStore::class),
            new RequestStack(),
        );

        $this->expectExceptionObject(new ValidationException('Order reads require a Shopware customer context token.', [
            '$.headers.'.PlatformRequest::HEADER_CONTEXT_TOKEN.' is required',
        ]));

        $gateway->getOrder('99999999999999999999999999999999', new RequestContext('shop.example'));
    }

    private function domainResolver(string $salesChannelId): SalesChannelDomainResolver
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('11111111111111111111111111111111');
        $domain->setSalesChannelId($salesChannelId);
        $domain->setLanguageId('33333333333333333333333333333333');
        $domain->setCurrencyId('44444444444444444444444444444444');
        $domain->setUrl('https://shop.example');
        $domains = new SalesChannelDomainCollection([$domain]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel_domain',
                $domains->count(),
                $domains,
                null,
                $criteria,
                $context,
            ),
        );

        return new SalesChannelDomainResolver($repository);
    }

    private function requestStackWithIncomingContextToken(string $token): RequestStack
    {
        $request = new Request([], [], [], [], [], ['HTTP_SW_CONTEXT_TOKEN' => $token]);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function uninitialized(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
