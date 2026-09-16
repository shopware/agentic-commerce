<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartItemAddRoute;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\SalesChannel\AbstractProductListRoute;
use Shopware\Core\Content\Product\SalesChannel\ProductListResponse;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartDeleteRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartItemAddRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartItemRemoveRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartItemUpdateRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\RecordingCartLoadRoute;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Gateway\Fixtures\StaticSalesChannelContextService;
use Swag\AgenticCommerce\Ucp\Adapter\ShopwareDiscountAdapter;
use Swag\AgenticCommerce\Ucp\Cart\CartSessionStore;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareCartGateway;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelContextResolver;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\ValidationException;
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
    public function testLoadRouteReturnsTheProvidedCart(): void
    {
        $storedCart = new Cart('stored-cart-token');
        $providedCart = new Cart('provided-cart-token');
        $loadRoute = new RecordingCartLoadRoute($storedCart);

        $response = $loadRoute->load(new Request(), $this->createSalesChannelContext('stored-cart-token'), $providedCart);

        self::assertSame($providedCart, $response->getCart());
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

    /**
     * A UCP cart id is a Shopware context token, and Shopware resolves a context and an empty
     * cart for any token it is shown. An external conformance agent asked for a cart nobody had
     * created and was handed a fresh one under the guessed id, HTTP 200. The protocol answer is
     * not_found.
     */
    public function testReadingACartNobodyCreatedIsNotFound(): void
    {
        $loadRoute = new RecordingCartLoadRoute(new Cart('guessed-token'));
        $gateway = $this->gateway(new Cart('guessed-token'), loadRoute: $loadRoute, persister: $this->unknownCartPersister());

        $this->expectException(ResourceNotFoundException::class);

        try {
            $gateway->getCart('guessed-token', new RequestContext('shop.test'));
        } finally {
            self::assertSame([], $loadRoute->loadedTokens, 'An unknown cart must be refused before Shopware is asked to create one.');
        }
    }

    /**
     * The guard first asked only whether Shopware had stored *any* context for the token. It
     * stores one for ordinary Store API traffic too -- a login, a currency or language switch --
     * so a token that had merely been seen by the shop passed as a cart id, and the cart load
     * route would answer 200 with a fabricated cart. Raised in review on #216.
     */
    #[Test]
    public function testATokenSeenOnlyByUnrelatedStoreApiTrafficIsNotACart(): void
    {
        $loadRoute = new RecordingCartLoadRoute(new Cart('seen-elsewhere'));
        $gateway = $this->gateway(
            new Cart('seen-elsewhere'),
            loadRoute: $loadRoute,
            persister: $this->unrelatedContextPersister(),
        );

        $this->expectException(ResourceNotFoundException::class);

        try {
            $gateway->getCart('seen-elsewhere', new RequestContext('shop.test'));
        } finally {
            self::assertSame([], $loadRoute->loadedTokens, 'A context stored by unrelated traffic is not a cart this plugin handed out.');
        }
    }

    public function testUpdatingApplyingToOrCancellingACartNobodyCreatedIsNotFound(): void
    {
        $requestContext = new RequestContext('shop.test');
        $calls = [
            static fn (ShopwareCartGateway $gateway) => $gateway->updateCart('guessed-token', [], [], $requestContext),
            static fn (ShopwareCartGateway $gateway) => $gateway->applyDiscountCode('guessed-token', 'SAVE10', $requestContext),
            static fn (ShopwareCartGateway $gateway) => $gateway->cancelCart('guessed-token', $requestContext),
        ];

        foreach ($calls as $call) {
            $gateway = $this->gateway(new Cart('guessed-token'), persister: $this->unknownCartPersister());
            $refused = false;
            try {
                $call($gateway);
            } catch (ResourceNotFoundException) {
                $refused = true;
            }
            self::assertTrue($refused, 'Every operation on an unknown cart id must answer not_found.');
        }
    }

    public function testCreatingACartRegistersItsTokenSoLaterReadsFindIt(): void
    {
        $saved = [];
        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->method('load')->willReturn([]);
        $persister->method('save')->willReturnCallback(static function (string $token, array $parameters) use (&$saved): void {
            $saved[$token] = $parameters;
        });
        $gateway = $this->gateway(new Cart('fresh-token'), persister: $persister);

        $gateway->createCart('fresh-token', [$this->ucpLineItem('product-a', 1)], [], new RequestContext('shop.test'));

        self::assertArrayHasKey('fresh-token', $saved);
        self::assertArrayHasKey('ucpCart', $saved['fresh-token']['swagAgenticCommerce']);
    }

    /**
     * Shopware removes a line item it cannot resolve and leaves no error on the cart, so the
     * gateway used to answer `201 Created`, `status: success`, no messages, and an empty cart --
     * and the agent's next call was checkout.
     */
    #[Test]
    public function testARequestedProductThatNeverArrivesInTheCartIsAnError(): void
    {
        $cart = new Cart('token-drop');
        $droppingAddRoute = new class extends AbstractCartItemAddRoute {
            public function getDecorated(): AbstractCartItemAddRoute
            {
                throw new \BadMethodCallException('Decoration is not supported in tests.');
            }

            /**
             * @param array<LineItem>|null $items
             */
            public function add(Request $request, Cart $cart, SalesChannelContext $context, ?array $items): \Shopware\Core\Checkout\Cart\SalesChannel\CartResponse
            {
                // Exactly what a parent product does: accepted, then silently not in the cart.
                return new \Shopware\Core\Checkout\Cart\SalesChannel\CartResponse($cart);
            }
        };

        $gateway = $this->gateway($cart, addRoute: $droppingAddRoute);

        try {
            $gateway->createCart('token-drop', [new UcpLineItem('parent-a', 'Acoustic Guitar', 25.08, 1)], [], new RequestContext('shop.test'));
            self::fail('Expected the dropped line item to be reported.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('could not be added', implode(' ', $exception->getViolations()));
            self::assertStringContainsString('parent-a', implode(' ', $exception->getViolations()));
        }
    }

    /**
     * Naming the id is the smaller half. An agent that asked for a parent has no way to know
     * that is what it did, so the error carries the variants it can buy instead -- otherwise its
     * only recovery is to walk the catalog again and guess differently.
     */
    #[Test]
    public function testRefusingAParentListsTheVariantsToBuyInstead(): void
    {
        $cart = new Cart('token-parent');
        $droppingAddRoute = new class extends AbstractCartItemAddRoute {
            public function getDecorated(): AbstractCartItemAddRoute
            {
                throw new \BadMethodCallException('Decoration is not supported in tests.');
            }

            /**
             * @param array<LineItem>|null $items
             */
            public function add(Request $request, Cart $cart, SalesChannelContext $context, ?array $items): \Shopware\Core\Checkout\Cart\SalesChannel\CartResponse
            {
                return new \Shopware\Core\Checkout\Cart\SalesChannel\CartResponse($cart);
            }
        };

        $gateway = $this->gateway(
            $cart,
            addRoute: $droppingAddRoute,
            productListRoute: $this->variantsOfParent('parent-a', ['variant-a', 'variant-b']),
        );

        try {
            $gateway->createCart('token-parent', [new UcpLineItem('parent-a', 'Acoustic Guitar', 25.08, 1)], [], new RequestContext('shop.test'));
            self::fail('Expected the dropped line item to be reported.');
        } catch (ValidationException $exception) {
            $violations = implode(' ', $exception->getViolations());
            self::assertStringContainsString('parent of a variant product', $violations);
            self::assertStringContainsString('variant-a', $violations);
            self::assertStringContainsString('variant-b', $violations);
            self::assertStringNotContainsString('not purchasable in this sales channel', $violations, 'that is the other branch, for a product with no variants to offer');
        }
    }

    /**
     * A single global `setLimit()` is spent in id order, so one wide product could consume the
     * whole window and leave the other refused parents with no variants listed -- reported as
     * "not purchasable in this sales channel", which is a false statement about a product the
     * agent could have bought. The bound has to be per parent.
     */
    #[Test]
    public function testAWideParentDoesNotStarveTheOtherRefusedParentsOfAlternatives(): void
    {
        $cart = new Cart('token-two-parents');
        $droppingAddRoute = new class extends AbstractCartItemAddRoute {
            public function getDecorated(): AbstractCartItemAddRoute
            {
                throw new \BadMethodCallException('Decoration is not supported in tests.');
            }

            /**
             * @param array<LineItem>|null $items
             */
            public function add(Request $request, Cart $cart, SalesChannelContext $context, ?array $items): \Shopware\Core\Checkout\Cart\SalesChannel\CartResponse
            {
                return new \Shopware\Core\Checkout\Cart\SalesChannel\CartResponse($cart);
            }
        };

        // 60 variants on the first parent: more than the old global limit of 50 on its own.
        $wide = [];
        for ($i = 0; $i < 60; ++$i) {
            $wide[] = \sprintf('wide-variant-%02d', $i);
        }

        $gateway = $this->gateway(
            $cart,
            addRoute: $droppingAddRoute,
            productListRoute: $this->variantsOfParents(['parent-wide' => $wide, 'parent-narrow' => ['narrow-variant']]),
        );

        try {
            $gateway->createCart(
                'token-two-parents',
                [
                    new UcpLineItem('parent-wide', 'Wide', 10.0, 1),
                    new UcpLineItem('parent-narrow', 'Narrow', 20.0, 1),
                ],
                [],
                new RequestContext('shop.test'),
            );
            self::fail('Expected both dropped line items to be reported.');
        } catch (ValidationException $exception) {
            $violations = implode(' ', $exception->getViolations());
            self::assertStringContainsString('narrow-variant', $violations, 'the second parent still gets its alternatives');
            self::assertStringNotContainsString('not purchasable in this sales channel', $violations);
            self::assertStringContainsString('wide-variant-00', $violations);
            self::assertStringNotContainsString('wide-variant-59', $violations, 'and the wide one is capped rather than dumped whole');
        }
    }

    /**
     * A product list route that answers the gateway's parentId query with the given variants,
     * the way Shopware does for a parent whose children are buyable in this sales channel.
     *
     * @param list<string> $variantIds
     */
    private function variantsOfParent(string $parentId, array $variantIds): AbstractProductListRoute
    {
        return $this->variantsOfParents([$parentId => $variantIds]);
    }

    /**
     * A product list route that answers the gateway's parentId query the way Shopware does:
     * honouring the `parentId` filter and the criteria's limit, in `parentId, id` order. The
     * limit matters — a fixture that returns everything regardless cannot show a starved parent.
     *
     * @param array<string, list<string>> $variantIdsByParent
     */
    private function variantsOfParents(array $variantIdsByParent): AbstractProductListRoute
    {
        $variants = [];
        foreach ($variantIdsByParent as $parentId => $variantIds) {
            foreach ($variantIds as $variantId) {
                $variant = new SalesChannelProductEntity();
                $variant->setId($variantId);
                $variant->setParentId((string) $parentId);
                $variants[] = $variant;
            }
        }

        $route = $this->createMock(AbstractProductListRoute::class);
        $route->method('load')->willReturnCallback(
            static function (Criteria $criteria, SalesChannelContext $context) use ($variants): ProductListResponse {
                $wanted = [];
                foreach ($criteria->getFilters() as $filter) {
                    if ($filter instanceof EqualsAnyFilter && 'parentId' === $filter->getField()) {
                        $wanted = array_map(strval(...), $filter->getValue());
                    }
                }

                $matching = array_values(array_filter(
                    $variants,
                    static fn (SalesChannelProductEntity $variant): bool => \in_array((string) $variant->getParentId(), $wanted, true),
                ));
                usort($matching, static fn (SalesChannelProductEntity $a, SalesChannelProductEntity $b): int => [$a->getParentId(), $a->getId()] <=> [$b->getParentId(), $b->getId()]);

                $limit = $criteria->getLimit();
                if (null !== $limit) {
                    $matching = \array_slice($matching, 0, $limit);
                }

                // ProductCollection is declared over ProductEntity, so building one from
                // SalesChannelProductEntity narrows it to ProductCollection<SalesChannelProductEntity>
                // -- which the invariant EntitySearchResult<ProductCollection> the route returns
                // does not accept. The runtime type is right; only the inferred generic is narrow.
                /** @var EntitySearchResult<ProductCollection> $result */
                $result = new EntitySearchResult(
                    'product',
                    \count($matching),
                    new ProductCollection($matching),
                    null,
                    $criteria,
                    Context::createDefaultContext(),
                );

                return new ProductListResponse($result);
            },
        );

        return $route;
    }

    private function gateway(
        Cart $cart,
        ?AbstractCartItemAddRoute $addRoute = null,
        ?RecordingCartItemRemoveRoute $removeRoute = null,
        ?RecordingCartItemUpdateRoute $updateRoute = null,
        ?RecordingCartDeleteRoute $deleteRoute = null,
        ?RecordingCartLoadRoute $loadRoute = null,
        ?SalesChannelContextPersister $persister = null,
        ?AbstractProductListRoute $productListRoute = null,
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
            new CartSessionStore($persister ?? $this->knownCartPersister()),
            // Only consulted when a requested line item did not make it into the cart, which the
            // recording add-route never does: it adds exactly what it was handed.
            $productListRoute ?? $this->createMock(AbstractProductListRoute::class),
        );
    }

    /**
     * The default for these tests: every token was handed out by cart.create, which is what the
     * existing tests assume. The refusal path has its own tests below.
     *
     * The payload carries the plugin's own marker. It previously carried only Shopware's
     * ordinary context keys, which passed because the guard accepted any stored context at
     * all -- so the fixture was asserting the hole rather than the rule.
     */
    private function knownCartPersister(): SalesChannelContextPersister
    {
        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->method('load')->willReturnCallback(static fn (string $token): array => [
            'token' => $token,
            'expired' => false,
            'swagAgenticCommerce' => ['ucpCart' => ['registered' => true]],
        ]);

        return $persister;
    }

    /**
     * Shopware writes a sales_channel_api_context row for ordinary Store API traffic -- a login,
     * a currency or language switch. Such a token was never a UCP cart, and accepting it would
     * let back in exactly what this guard refuses.
     */
    private function unrelatedContextPersister(): SalesChannelContextPersister
    {
        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->method('load')->willReturn(['token' => 'someone-elses', 'expired' => false, 'currencyId' => 'abc']);
        $persister->expects(self::never())->method('save');

        return $persister;
    }

    private function unknownCartPersister(): SalesChannelContextPersister
    {
        $persister = $this->createMock(SalesChannelContextPersister::class);
        $persister->method('load')->willReturn([]);
        $persister->expects(self::never())->method('save');

        return $persister;
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
            $this->createMock(SalesChannelContextPersister::class),
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
