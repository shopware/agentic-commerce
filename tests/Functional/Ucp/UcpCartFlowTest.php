<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Functional\Ucp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the UCP cart capability (create / get / update / cancel) through the booted kernel against a
 * seeded storefront product, replacing the equivalent deployed-stack smoke assertions. See
 * {@see UcpFlowTestBehaviour} for the request-context setup.
 *
 * @internal
 */
final class UcpCartFlowTest extends TestCase
{
    use UcpFlowTestBehaviour;

    #[Test]
    public function testCartCreateGetUpdateAndCancel(): void
    {
        $this->configureUcpRuntime();
        $productId = $this->seedStorefrontProduct('Kernel Test Album');
        $lineItem = static fn (int $quantity): array => ['item' => ['id' => $productId, 'title' => 'Kernel Test Album', 'price' => 19.99], 'quantity' => $quantity];

        $create = $this->ucpRequest('POST', '/ucp/v1/carts', ['line_items' => [$lineItem(1)]]);
        self::assertSame(Response::HTTP_CREATED, $create->getStatusCode());
        $cart = $this->decode($create);
        self::assertCount(1, $cart['line_items'], 'Expected cart.create to create one line item.');
        $cartId = $cart['id'];
        self::assertNotEmpty($cartId);

        $get = $this->ucpRequest('GET', '/ucp/v1/carts/'.$cartId);
        self::assertSame(Response::HTTP_OK, $get->getStatusCode());
        self::assertSame($cartId, $this->decode($get)['id']);

        $update = $this->ucpRequest('PATCH', '/ucp/v1/carts/'.$cartId, ['id' => $cartId, 'line_items' => [$lineItem(2)]]);
        self::assertSame(Response::HTTP_OK, $update->getStatusCode());
        self::assertSame(2, $this->decode($update)['line_items'][0]['quantity'], 'Expected cart.update to change the line-item quantity.');

        $cancel = $this->ucpRequest('POST', '/ucp/v1/carts/'.$cartId.'/cancel', []);
        self::assertSame(Response::HTTP_OK, $cancel->getStatusCode());
        self::assertCount(0, $this->decode($cancel)['line_items'], 'Expected cart.cancel to empty the cart.');
    }
}
