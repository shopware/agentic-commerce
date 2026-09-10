<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\OrderRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Adapter\ShopwareCheckoutAdapter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompleter;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutCompletionStoreInterface;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilder;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutGuestAddressPayloadResolver;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionManager;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutSessionStore;
use Swag\AgenticCommerce\Ucp\Checkout\OrderPermalinkBuilder;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCartGateway;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareOrderGateway;
use Swag\AgenticCommerce\Ucp\SalesChannel\ContextTokenGenerator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareCheckoutAdapterTest extends TestCase
{
    #[Test]
    public function testCompletedCheckoutReadsOrderWithCheckoutSalesChannelContext(): void
    {
        $checkoutId = 'checkout-context-token';
        $shopwareContextToken = 'rotated-shopware-context-token';
        $salesChannelId = '22222222222222222222222222222222';
        $orderId = '99999999999999999999999999999999';

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn($salesChannelId);
        $salesChannelContext->method('getCustomer')->willReturn(new CustomerEntity());

        $contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $contextService->expects(static::once())
            ->method('get')
            ->willReturnCallback(static function (SalesChannelContextServiceParameters $parameters) use ($shopwareContextToken, $salesChannelId, $salesChannelContext): SalesChannelContext {
                self::assertSame($salesChannelId, $parameters->getSalesChannelId());
                self::assertSame($shopwareContextToken, $parameters->getToken());
                self::assertSame('customer-id', $parameters->getCustomerId());

                return $salesChannelContext;
            });

        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->expects(static::exactly(2))
            ->method('load')
            ->willReturnCallback(static function (string $token, string $requestedSalesChannelId, ?string $customerId = null) use ($checkoutId, $shopwareContextToken, $salesChannelId, $orderId): array {
                self::assertSame($salesChannelId, $requestedSalesChannelId);
                self::assertNull($customerId);

                if ($token === $checkoutId) {
                    return [
                        'swagAgenticCommerce' => [
                            'ucpCheckout' => [
                                'status' => CheckoutStatus::Completed->value,
                                'orderId' => $orderId,
                                'shopwareContextToken' => $shopwareContextToken,
                            ],
                        ],
                    ];
                }

                self::assertSame($shopwareContextToken, $token);

                return ['customerId' => 'customer-id'];
            });

        $order = $this->order($orderId);
        $orderRoute = $this->createMock(AbstractOrderRoute::class);
        $orderRoute->expects(static::once())
            ->method('load')
            ->willReturnCallback(static function (
                \Symfony\Component\HttpFoundation\Request $_request,
                SalesChannelContext $context,
                Criteria $criteria,
            ) use ($salesChannelContext, $orderId, $order): OrderRouteResponse {
                self::assertSame($salesChannelContext, $context);
                self::assertSame([$orderId], $criteria->getIds());

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

        $sessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver($this->domainResolver($salesChannelId), $contextService, $persister);
        $mapper = new ShopwareDataMapper();

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        $adapter = new ShopwareCheckoutAdapter(
            $this->uninitialized(ShopwareCartGateway::class),
            new ShopwareOrderGateway($contextResolver, $this->createMock(AbstractCartOrderRoute::class), $orderRoute, $sessionStore, new RequestStack()),
            $mapper,
            $sessionStore,
            new CheckoutSessionManager($sessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($sessionStore),
            $this->continueUrlBuilder(),
            $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
        );

        $checkout = $adapter->getCheckout($checkoutId, new RequestContext('shop.example'));

        self::assertSame($checkoutId, $checkout->id);
        self::assertSame(CheckoutStatus::Completed, $checkout->status);
        self::assertSame($orderId, $checkout->order?->id);
    }

    #[Test]
    public function testCompletedCheckoutRequiresStoredShopwareContextToken(): void
    {
        $checkoutId = 'checkout-context-token';
        $salesChannelId = '22222222222222222222222222222222';

        $contextService = $this->createMock(SalesChannelContextServiceInterface::class);
        $contextService->expects(static::never())->method('get');

        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->expects(static::once())
            ->method('load')
            ->with($checkoutId, $salesChannelId, null)
            ->willReturn([
                'swagAgenticCommerce' => [
                    'ucpCheckout' => [
                        'status' => CheckoutStatus::Completed->value,
                        'orderId' => '99999999999999999999999999999999',
                    ],
                ],
            ]);

        $sessionStore = new CheckoutSessionStore($persister);
        $contextResolver = new SalesChannelContextResolver($this->domainResolver($salesChannelId), $contextService, $persister);

        $completionStore = $this->createMock(CheckoutCompletionStoreInterface::class);
        $completionStore->method('completedOrderId')->willReturn(null);

        $adapter = new ShopwareCheckoutAdapter(
            $this->uninitialized(ShopwareCartGateway::class),
            new ShopwareOrderGateway($contextResolver, $this->createMock(AbstractCartOrderRoute::class), $this->createMock(AbstractOrderRoute::class), $sessionStore, new RequestStack()),
            new ShopwareDataMapper(),
            $sessionStore,
            new CheckoutSessionManager($sessionStore),
            $completionStore,
            new CheckoutGuestAddressPayloadResolver($sessionStore),
            $this->continueUrlBuilder(),
            $this->uninitialized(CheckoutCompleter::class),
            $contextResolver,
            new ContextTokenGenerator(),
            new OrderPermalinkBuilder(),
        );

        $this->expectExceptionObject(new ValidationException('Completed checkout session is missing its Shopware context token.'));

        $adapter->getCheckout($checkoutId, new RequestContext('shop.example'));
    }

    private function order(string $orderId): OrderEntity
    {
        $taxes = new CalculatedTaxCollection();
        $taxRules = new TaxRuleCollection();
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setPrice(new CartPrice(10.0, 10.0, 10.0, $taxes, $taxRules, CartPrice::TAX_STATE_GROSS));
        $order->setShippingCosts(new CalculatedPrice(0.0, 0.0, $taxes, $taxRules));

        return $order;
    }

    private function continueUrlBuilder(): CheckoutContinueUrlBuilder
    {
        $repository = $this->createMock(UcpConfigRepositoryInterface::class);
        $repository->method('find')->willReturn(new UcpConfig());

        return new CheckoutContinueUrlBuilder(new UcpConfigService(
            $repository,
            $this->createMock(LegacyConfigStoreInterface::class),
        ));
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
