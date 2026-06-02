<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Embedded\ShopwareEmbeddedPageRenderer;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Model\Cart\Cart;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/** @internal */
final class ShopwareEmbeddedPageRendererTest extends TestCase
{
    #[Test]
    public function testItRendersCartPageThroughTwig(): void
    {
        $renderer = new ShopwareEmbeddedPageRenderer(
            new class implements CartCapabilityInterface {
                public function describe(): CapabilityDescriptor
                {
                    return new CapabilityDescriptor('cart', '1.0.0', 'https://ucp.dev', 'https://ucp.dev/schema');
                }

                public function createCart(CartCreateRequest $request, RequestContext $context): Cart
                {
                    throw new \BadMethodCallException('Not needed for this test.');
                }

                public function getCart(string $id, RequestContext $context): Cart
                {
                    return new Cart($id, [
                        new LineItem('sku-1', 'Demo <Guitar>', 10.0, 2),
                    ], 'EUR', [
                        new Money('total', 20.0, 'EUR 20.00'),
                    ]);
                }

                public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
                {
                    throw new \BadMethodCallException('Not needed for this test.');
                }

                public function cancelCart(string $id, RequestContext $context): Cart
                {
                    throw new \BadMethodCallException('Not needed for this test.');
                }
            },
            $this->checkoutCapability(),
            $this->twig(),
        );
        $request = Request::create('https://shop.example/ucp/embedded/cart/cart-id', server: [
            'HTTP_ORIGIN' => 'https://assistant.example',
        ]);
        $request->attributes->set('ucp_request_context', new RequestContext('shop.example'));

        $response = $renderer->render('cart', 'cart-id', $request);

        self::assertNotNull($response);
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('Demo &lt;Guitar&gt;', $response->getContent() ?: '');
        self::assertStringContainsString('EUR 20.00', $response->getContent() ?: '');
        self::assertStringContainsString('"targetOrigin":"https:\/\/assistant.example"', $response->getContent() ?: '');
        self::assertStringContainsString("emit('ucp.embedded.ready')", $response->getContent() ?: '');
    }

    #[Test]
    public function testItRendersCheckoutContinueLink(): void
    {
        $renderer = new ShopwareEmbeddedPageRenderer(
            $this->cartCapability(),
            $this->checkoutCapability(),
            $this->twig(),
        );
        $request = Request::create('https://shop.example/ucp/embedded/checkout/checkout-id');
        $request->attributes->set('ucp_request_context', new RequestContext('shop.example'));

        $response = $renderer->render('checkout', 'checkout-id', $request);

        self::assertNotNull($response);
        self::assertStringContainsString('Checkout session', $response->getContent() ?: '');
        self::assertStringContainsString('ready_for_complete', $response->getContent() ?: '');
        self::assertStringContainsString('href="https://shop.example/checkout/confirm"', $response->getContent() ?: '');
    }

    private function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../src/Resources/views', 'SwagAgenticCommerce');

        return new Environment($loader);
    }

    private function cartCapability(): CartCapabilityInterface
    {
        return new class implements CartCapabilityInterface {
            public function describe(): CapabilityDescriptor
            {
                return new CapabilityDescriptor('cart', '1.0.0', 'https://ucp.dev', 'https://ucp.dev/schema');
            }

            public function createCart(CartCreateRequest $request, RequestContext $context): Cart
            {
                throw new \BadMethodCallException('Not needed for this test.');
            }

            public function getCart(string $id, RequestContext $context): Cart
            {
                return new Cart($id, [], 'EUR');
            }

            public function updateCart(CartUpdateRequest $request, RequestContext $context): Cart
            {
                throw new \BadMethodCallException('Not needed for this test.');
            }

            public function cancelCart(string $id, RequestContext $context): Cart
            {
                throw new \BadMethodCallException('Not needed for this test.');
            }
        };
    }

    private function checkoutCapability(): CheckoutCapabilityInterface
    {
        return new class implements CheckoutCapabilityInterface {
            public function describe(): CapabilityDescriptor
            {
                return new CapabilityDescriptor('checkout', '1.0.0', 'https://ucp.dev', 'https://ucp.dev/schema');
            }

            public function createCheckout(CheckoutCreateRequest $request, RequestContext $context): Checkout
            {
                throw new \BadMethodCallException('Not needed for this test.');
            }

            public function getCheckout(string $id, RequestContext $context): Checkout
            {
                return new Checkout(
                    $id,
                    CheckoutStatus::ReadyForComplete,
                    'EUR',
                    [new LineItem('sku-1', 'Demo Guitar', 10.0, 1)],
                    [new Money('total', 10.0, 'EUR 10.00')],
                    continueUrl: 'https://shop.example/checkout/confirm',
                );
            }

            public function updateCheckout(CheckoutUpdateRequest $request, RequestContext $context): Checkout
            {
                throw new \BadMethodCallException('Not needed for this test.');
            }

            public function completeCheckout(string $id, RequestContext $context): Checkout
            {
                throw new \BadMethodCallException('Not needed for this test.');
            }

            public function cancelCheckout(string $id, RequestContext $context): Checkout
            {
                throw new \BadMethodCallException('Not needed for this test.');
            }
        };
    }
}
