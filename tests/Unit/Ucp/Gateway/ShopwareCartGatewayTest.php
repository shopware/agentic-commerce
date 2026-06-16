<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartDeleteRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartItemAddRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartItemRemoveRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartItemUpdateRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartLoadRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\StaticSalesChannelContextService;
use Swag\AgenticCommerce\Ucp\Adapter\ShopwareDiscountAdapter;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCartGateway;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Common\LineItem as UcpLineItem;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareCartGatewayTest extends TestCase
{
    #[Test]
    public function testCreateCartAddsRequestedProductsAndDiscounts(): void
    {
        $cart = new Cart('cart-token');
        $addRoute = new RecordingCartItemAddRoute();
        $gateway = $this->gateway($cart, addRoute: $addRoute);

        $result = $gateway->createCart(
            'cart-token',
            [$this->ucpLineItem('product-a', 2)],
            ['SUMMER10'],
            new RequestContext('shop.test'),
        );

        self::assertCount(1, $addRoute->addedPayloads);
        self::assertSame([[
            'id' => 'product-a',
            'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
            'referencedId' => 'product-a',
            'quantity' => 2,
        ], [
            'id' => $this->promotionLineItemId('SUMMER10'),
            'type' => LineItem::PROMOTION_LINE_ITEM_TYPE,
            'referencedId' => 'SUMMER10',
            'quantity' => 1,
        ]], $addRoute->addedPayloads[0]);
        self::assertTrue($cart->getLineItems()->has('product-a'));
        self::assertTrue($cart->getLineItems()->has($this->promotionLineItemId('SUMMER10')));
        self::assertSame('cart-token', $result->id);
        self::assertCount(2, $result->lineItems);
    }

    #[Test]
    public function testGetCartReturnsLoadedCart(): void
    {
        $cart = new Cart('cart-token');
        $cart->add($this->productLineItem('product-a-line-item', 'product-a', 2));
        $loadRoute = new RecordingCartLoadRoute($cart);
        $gateway = $this->gateway($cart, loadRoute: $loadRoute);

        $result = $gateway->getCart('cart-token', new RequestContext('shop.test'));

        self::assertSame(['cart-token'], $loadRoute->loadedTokens);
        self::assertSame('cart-token', $result->id);
        self::assertCount(1, $result->lineItems);
        self::assertSame('product-a', $result->lineItems[0]->id);
    }

    #[Test]
    public function testUpdateCartSynchronizesAuthoritativeProductsAndDiscounts(): void
    {
        $cart = new Cart('cart-token');
        $cart->add($this->productLineItem('product-a-line-item', 'product-a', 1));
        $cart->add($this->productLineItem('product-b-line-item', 'product-b', 1));
        $cart->add($this->promotionLineItem('OLD10'));

        $addRoute = new RecordingCartItemAddRoute();
        $updateRoute = new RecordingCartItemUpdateRoute();
        $removeRoute = new RecordingCartItemRemoveRoute();
        $gateway = $this->gateway($cart, $addRoute, $removeRoute, $updateRoute);

        $result = $gateway->updateCart(
            'cart-token',
            [
                $this->ucpLineItem('product-a', 3),
                $this->ucpLineItem('product-c', 2),
            ],
            ['NEW10'],
            new RequestContext('shop.test'),
        );

        self::assertSame(['product-b-line-item', $this->promotionLineItemId('OLD10')], $removeRoute->removedIds);
        self::assertSame([[
            'id' => 'product-a-line-item',
            'quantity' => 3,
        ]], $updateRoute->updatedPayloads[0]);
        self::assertSame([[
            'id' => 'product-c',
            'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
            'referencedId' => 'product-c',
            'quantity' => 2,
        ], [
            'id' => $this->promotionLineItemId('NEW10'),
            'type' => LineItem::PROMOTION_LINE_ITEM_TYPE,
            'referencedId' => 'NEW10',
            'quantity' => 1,
        ]], $addRoute->addedPayloads[0]);
        self::assertSame(3, $cart->getLineItems()->get('product-a-line-item')?->getQuantity());
        self::assertFalse($cart->getLineItems()->has('product-b-line-item'));
        self::assertTrue($cart->getLineItems()->has('product-c'));
        self::assertFalse($cart->getLineItems()->has($this->promotionLineItemId('OLD10')));
        self::assertTrue($cart->getLineItems()->has($this->promotionLineItemId('NEW10')));
        self::assertCount(3, $result->lineItems);
    }

    #[Test]
    public function testDiscountAdapterAppliesDiscountWithoutRemovingExistingCartLineItems(): void
    {
        $cart = new Cart('cart-token');
        $cart->add($this->productLineItem('product-line-item', 'product-id', 2));
        $cart->add($this->promotionLineItem('WELCOME'));

        $addRoute = new RecordingCartItemAddRoute();
        $removeRoute = new RecordingCartItemRemoveRoute();
        $gateway = $this->gateway($cart, $addRoute, $removeRoute);

        $result = (new ShopwareDiscountAdapter($gateway))->applyCartDiscount(
            'cart-token',
            new DiscountCode('SUMMER10'),
            new RequestContext('shop.test'),
        );

        self::assertTrue($cart->getLineItems()->has('product-line-item'));
        self::assertTrue($cart->getLineItems()->has($this->promotionLineItemId('WELCOME')));
        self::assertTrue($cart->getLineItems()->has($this->promotionLineItemId('SUMMER10')));
        self::assertSame([], $removeRoute->removedIds);
        self::assertCount(1, $addRoute->addedPayloads);
        self::assertSame([[
            'id' => $this->promotionLineItemId('SUMMER10'),
            'type' => LineItem::PROMOTION_LINE_ITEM_TYPE,
            'referencedId' => 'SUMMER10',
            'quantity' => 1,
        ]], $addRoute->addedPayloads[0]);
        self::assertSame('cart-token', $result->id);
        self::assertCount(3, $result->lineItems);
    }

    #[Test]
    public function testApplyingExistingDiscountCodeDoesNotAddItAgain(): void
    {
        $cart = new Cart('cart-token');
        $cart->add($this->productLineItem('product-line-item', 'product-id', 2));
        $cart->add($this->promotionLineItem('SUMMER10'));

        $addRoute = new RecordingCartItemAddRoute();
        $removeRoute = new RecordingCartItemRemoveRoute();
        $gateway = $this->gateway($cart, $addRoute, $removeRoute);

        $gateway->applyDiscountCode('cart-token', 'SUMMER10', new RequestContext('shop.test'));

        self::assertTrue($cart->getLineItems()->has('product-line-item'));
        self::assertSame([], $addRoute->addedPayloads);
        self::assertSame([], $removeRoute->removedIds);
        self::assertCount(2, $cart->getLineItems());
    }

    #[Test]
    public function testCancelCartDeletesNonEmptyCartBeforeReturningLatestCart(): void
    {
        $cart = new Cart('cart-token');
        $cart->add($this->productLineItem('product-line-item', 'product-id', 2));
        $deleteRoute = new RecordingCartDeleteRoute();
        $loadRoute = new RecordingCartLoadRoute($cart);
        $gateway = $this->gateway($cart, deleteRoute: $deleteRoute, loadRoute: $loadRoute);

        $result = $gateway->cancelCart('cart-token', new RequestContext('shop.test'));

        self::assertSame(1, $deleteRoute->deleteCalls);
        self::assertSame(['cart-token', 'cart-token'], $loadRoute->loadedTokens);
        self::assertSame('cart-token', $result->id);
    }

    #[Test]
    public function testCancelCartSkipsDeleteForEmptyCart(): void
    {
        $cart = new Cart('cart-token');
        $deleteRoute = new RecordingCartDeleteRoute();
        $gateway = $this->gateway($cart, deleteRoute: $deleteRoute);

        $gateway->cancelCart('cart-token', new RequestContext('shop.test'));

        self::assertSame(0, $deleteRoute->deleteCalls);
    }

    #[Test]
    public function testLoadCheckoutCartReturnsResolvedContextAndLoadedCart(): void
    {
        $cart = new Cart('cart-token');
        $cart->add($this->productLineItem('product-line-item', 'product-id', 2));
        $gateway = $this->gateway($cart);

        [$salesChannelContext, $loadedCart] = $gateway->loadCheckoutCart('cart-token', new RequestContext('shop.test'));

        self::assertSame('cart-token', $salesChannelContext->getToken());
        self::assertSame($cart, $loadedCart);
    }

    #[Test]
    public function testSynchronizeCheckoutCartReturnsResolvedContextAndSynchronizedCart(): void
    {
        $cart = new Cart('cart-token');
        $addRoute = new RecordingCartItemAddRoute();
        $gateway = $this->gateway($cart, addRoute: $addRoute);

        [$salesChannelContext, $synchronizedCart] = $gateway->synchronizeCheckoutCart(
            'cart-token',
            [$this->ucpLineItem('product-a', 2)],
            [],
            new RequestContext('shop.test'),
        );

        self::assertSame('cart-token', $salesChannelContext->getToken());
        self::assertSame($cart, $synchronizedCart);
        self::assertSame([[
            'id' => 'product-a',
            'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
            'referencedId' => 'product-a',
            'quantity' => 2,
        ]], $addRoute->addedPayloads[0]);
    }

    private function gateway(
        Cart $cart,
        ?RecordingCartItemAddRoute $addRoute = null,
        ?RecordingCartItemRemoveRoute $removeRoute = null,
        ?RecordingCartItemUpdateRoute $updateRoute = null,
        ?RecordingCartDeleteRoute $deleteRoute = null,
        ?RecordingCartLoadRoute $loadRoute = null,
    ): ShopwareCartGateway {
        $salesChannelContext = $this->createSalesChannelContext($cart->getToken());

        return new ShopwareCartGateway(
            $this->contextResolver($salesChannelContext),
            $loadRoute ?? new RecordingCartLoadRoute($cart),
            $addRoute ?? new RecordingCartItemAddRoute(),
            $updateRoute ?? new RecordingCartItemUpdateRoute(),
            $removeRoute ?? new RecordingCartItemRemoveRoute(),
            $deleteRoute ?? new RecordingCartDeleteRoute(),
            new ShopwareDataMapper(),
            new ShopwareVersionDetector(versionOverride: '6.6.0.0'),
        );
    }

    private function createSalesChannelContext(string $token): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getToken')->willReturn($token);
        $context->method('getCurrency')->willReturn($currency);

        return $context;
    }

    private function contextResolver(SalesChannelContext $salesChannelContext): SalesChannelContextResolver
    {
        $domain = new SalesChannelDomainEntity();
        $domain->setId('domain-id');
        $domain->setUrl('https://shop.test');
        $domain->setSalesChannelId('sales-channel-id');
        $domain->setLanguageId('language-id');
        $domain->setCurrencyId('currency-id');

        /** @var EntityRepository<SalesChannelDomainCollection>&MockObject $domainRepository */
        $domainRepository = $this->createMock(EntityRepository::class);
        $domainRepository->method('search')->willReturn(new EntitySearchResult(
            'sales_channel_domain',
            1,
            new SalesChannelDomainCollection([$domain]),
            null,
            new Criteria(),
            Context::createDefaultContext(),
        ));

        return new SalesChannelContextResolver(
            new SalesChannelDomainResolver($domainRepository),
            new StaticSalesChannelContextService($salesChannelContext),
        );
    }

    private function promotionLineItemId(string $code): string
    {
        return Uuid::fromStringToHex('promotion-'.$code);
    }

    private function productLineItem(string $id, string $referencedId, int $quantity): LineItem
    {
        return (new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $referencedId, $quantity))->setRemovable(true)->setStackable(true);
    }

    private function promotionLineItem(string $code): LineItem
    {
        return (new LineItem($this->promotionLineItemId($code), LineItem::PROMOTION_LINE_ITEM_TYPE, $code))->setRemovable(true);
    }

    private function ucpLineItem(string $id, int $quantity): UcpLineItem
    {
        return new UcpLineItem($id, $id, 0.0, $quantity);
    }
}
