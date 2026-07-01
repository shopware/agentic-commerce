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
use Ucp\Sdk\Model\Checkout\Checkout;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Money;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

/** @internal */
final class ShopwareEmbeddedPageRendererTest extends TestCase
{
    private Cart $cart;

    private Checkout $checkout;

    private ShopwareEmbeddedPageRenderer $renderer;

    protected function setUp(): void
    {
        $this->cart = new Cart('cart-id', [], 'EUR');
        $this->checkout = new Checkout(
            'checkout-id',
            CheckoutStatus::ReadyForComplete,
            'EUR',
            [new LineItem('sku-1', 'Demo Guitar', 10.0, 1)],
            [new Money('total', 10.0, 'EUR 10.00')],
            continueUrl: 'https://shop.example/checkout/confirm',
        );

        $cartCapability = $this->createMock(CartCapabilityInterface::class);
        $cartCapability->method('getCart')->willReturnCallback(fn (): Cart => $this->cart);

        $checkoutCapability = $this->createMock(CheckoutCapabilityInterface::class);
        $checkoutCapability->method('getCheckout')->willReturnCallback(fn (): Checkout => $this->checkout);

        $runtimeConfigurationResolver = $this->createMock(RuntimeConfigurationResolverInterface::class);
        $runtimeConfigurationResolver
            ->method('resolve')
            ->willReturnCallback(static fn (HttpRequest $request): RuntimeConfiguration => new RuntimeConfiguration(
                '2026-04-08',
                'https://'.(parse_url($request->absoluteUri, \PHP_URL_HOST) ?: 'shop.example'),
            ));

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../src/Resources/views', 'SwagAgenticCommerce');

        $this->renderer = new ShopwareEmbeddedPageRenderer(
            $cartCapability,
            $checkoutCapability,
            new Environment($loader),
            $runtimeConfigurationResolver,
        );
    }

    #[Test]
    public function testItRendersCartPageThroughTwig(): void
    {
        $this->cart = new Cart('cart-id', [
            new LineItem('sku-1', 'Demo <Guitar>', 10.0, 2),
        ], 'EUR', [
            new Money('total', 20.0, 'EUR 20.00'),
        ]);

        $request = Request::create('https://shop.example/ucp/embedded/cart/cart-id', server: [
            'HTTP_ORIGIN' => 'https://assistant.example',
        ]);
        $request->attributes->set('ucp_request_context', new RequestContext('shop.example'));

        $response = $this->renderer->render('cart', 'cart-id', $request);

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
        $request = Request::create('https://shop.example/ucp/embedded/checkout/checkout-id');
        $request->attributes->set('ucp_request_context', new RequestContext('shop.example'));

        $response = $this->renderer->render('checkout', 'checkout-id', $request);

        self::assertNotNull($response);
        self::assertStringContainsString('Checkout session', $response->getContent() ?: '');
        self::assertStringContainsString('ready_for_complete', $response->getContent() ?: '');
        self::assertStringContainsString('href="https://shop.example/checkout/confirm"', $response->getContent() ?: '');
    }

    #[Test]
    public function testItRendersWithoutPrebuiltUcpRequestContext(): void
    {
        $request = Request::create('https://shop.example/ucp/embedded/cart/cart-id', server: [
            'HTTP_ORIGIN' => 'https://assistant.example',
        ]);

        $response = $this->renderer->render('cart', 'cart-id', $request);

        self::assertNotNull($response);
        self::assertStringContainsString('UCP embedded cart', $response->getContent() ?: '');
    }
}
