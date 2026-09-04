<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\Error;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Promotion\Cart\PromotionCartAddedInformationError;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\Ucp\Gateway\ShopwareDataMapper;
use Ucp\Sdk\Enum\CheckoutStatus;
use Ucp\Sdk\Enum\UcpCapability;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Enum\UcpResponseStatus;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Validation\GeneratedSchemaValidator;
use Ucp\Sdk\Model\Protocol\UcpEnvelope;
use Ucp\Sdk\Model\Protocol\UcpOperationPayload;
use Ucp\Sdk\Model\Protocol\UcpOperationResponse;

/**
 * Validates mapped responses against the SDK's own generated schemas.
 *
 * ShoppingOperationExecutor::response() validates every response before returning
 * it, so a mapping the schema rejects surfaces to the agent as a server error even
 * when the request was fine and the write succeeded. That is exactly what happened
 * with discount codes: applying one adds a Shopware promotion line with a negative
 * unit price, LineItem::toArray() emitted it as a per-line `{"type": "subtotal"}`,
 * and types/total.json constrains `subtotal` to `minimum: 0`.
 *
 * These tests run the real validator against the real schemas, so they fail if the
 * mapping and the spec drift apart again.
 *
 * @internal
 */
#[CoversClass(ShopwareDataMapper::class)]
final class UcpResponseSchemaTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function cartOperationProvider(): iterable
    {
        // Every operation that answers with a cart revalidates the same mapping.
        // discount.apply is simply where an agent notices first.
        yield 'discount.apply' => ['discount.apply.response'];
        yield 'cart.get' => ['cart.get.response'];
        yield 'cart.update' => ['cart.update.response'];
        yield 'cart.create' => ['cart.create.response'];
    }

    #[DataProvider('cartOperationProvider')]
    #[Test]
    public function testACartCarryingAPromotionValidatesAgainstItsResponseSchema(string $schema): void
    {
        $cart = (new ShopwareDataMapper())->toCart($this->cartWithPromotion(), $this->salesChannelContext());

        $this->assertValidates($schema, $cart, UcpCapability::Cart);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function checkoutOperationProvider(): iterable
    {
        yield 'checkout.get' => ['checkout.get.response'];
        yield 'checkout.update' => ['checkout.update.response'];
        yield 'checkout.create' => ['checkout.create.response'];
    }

    #[DataProvider('checkoutOperationProvider')]
    #[Test]
    public function testACheckoutCarryingAPromotionValidatesAgainstItsResponseSchema(string $schema): void
    {
        $checkout = (new ShopwareDataMapper())->toCheckout(
            $this->cartWithPromotion(),
            $this->salesChannelContext(),
            CheckoutStatus::Incomplete,
            null,
            'https://example.com/checkout/cart-token',
        );

        $this->assertValidates($schema, $checkout, UcpCapability::Checkout);
    }

    #[Test]
    public function testACartWithoutAPromotionAlsoValidates(): void
    {
        $cart = (new ShopwareDataMapper())->toCart($this->cartWithoutPromotion(), $this->salesChannelContext());

        $this->assertValidates('cart.get.response', $cart, UcpCapability::Cart);
    }

    private function assertValidates(string $schema, UcpOperationPayload $payload, UcpCapability $capability): void
    {
        // Mirrors ShoppingOperationExecutor::response() exactly: the payload's own
        // fields plus the `ucp` envelope, validated as one document.
        $response = new UcpOperationResponse(
            $payload,
            UcpEnvelope::response(UcpProtocolVersion::V20260408->value, UcpResponseStatus::Success, $capability),
        );
        $document = $response->toArray();

        try {
            $this->validator()->validate($schema, $document);
        } catch (ValidationException $exception) {
            self::fail(\sprintf(
                '%s rejected the mapped response: %s',
                $schema,
                implode('; ', $exception->getViolations()),
            ));
        }

        self::assertArrayHasKey('ucp', $document, 'The validated document must be the envelope the executor returns.');
    }

    private function validator(): GeneratedSchemaValidator
    {
        // Resolved the same way UcpSdkExtension does, so it holds whether the SDK is
        // installed from a path repository or from vendor/.
        $coreRoot = \dirname((string) (new \ReflectionClass(GeneratedSchemaValidator::class))->getFileName(), 4);

        return new GeneratedSchemaValidator($coreRoot.'/resources/schema/generated/2026-08-25');
    }

    private function cartWithPromotion(): Cart
    {
        $cart = $this->cartWithoutPromotion();
        $promotion = $this->lineItem('line-2', LineItem::PROMOTION_LINE_ITEM_TYPE, 'Summer Sale', -2.0);
        $lineItems = $cart->getLineItems();
        $lineItems->add($promotion);
        $cart->setLineItems($lineItems);
        $cart->setPrice(new CartPrice(8.0, 8.0, 8.0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

        // A cart that took a promotion really does carry this, and the fixture not
        // carrying it is why the mapping's `type: cart_error` survived: applying a valid
        // code leaves `promotion-discount-added` on the cart
        // (PromotionCartAddedInformationError, LEVEL_NOTICE, persistent), and
        // types/message.json admits only error, warning or info. Every case in both
        // providers goes through this fixture, so the whole cart-and-checkout surface is
        // covered rather than discount.apply alone.
        $cart->addErrors(new PromotionCartAddedInformationError($promotion));

        return $cart;
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function cartErrorLevelProvider(): iterable
    {
        yield 'notice' => [Error::LEVEL_NOTICE];
        yield 'warning' => [Error::LEVEL_WARNING];
        yield 'error' => [Error::LEVEL_ERROR];
    }

    #[DataProvider('cartErrorLevelProvider')]
    #[Test]
    public function testACartCarryingAnErrorOfAnyLevelValidatesAgainstItsResponseSchema(int $level): void
    {
        $shopwareCart = $this->cartWithoutPromotion();
        $shopwareCart->addErrors(new CartErrorFixture($level, 'Something happened to this cart', 'cart-changed'));

        $cart = (new ShopwareDataMapper())->toCart($shopwareCart, $this->salesChannelContext());

        $this->assertValidates('cart.get.response', $cart, UcpCapability::Cart);
    }

    private function cartWithoutPromotion(): Cart
    {
        $cart = new Cart('cart-token');
        $cart->setLineItems(new LineItemCollection([
            $this->lineItem('line-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'Nice Shirt', 10.0),
        ]));
        $cart->setPrice(new CartPrice(10.0, 10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));

        return $cart;
    }

    private function lineItem(string $id, string $type, string $label, float $unitPrice): LineItem
    {
        $lineItem = new LineItem($id, $type, $id, 1);
        $lineItem->setLabel($label);
        $lineItem->setPrice(new CalculatedPrice($unitPrice, $unitPrice, new CalculatedTaxCollection(), new TaxRuleCollection()));

        return $lineItem;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $currency = new CurrencyEntity();
        $currency->setId('99999999999999999999999999999999');
        $currency->setIsoCode('EUR');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getToken')->willReturn('cart-token');

        return $context;
    }
}
