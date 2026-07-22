<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Validation\CartIdCheckoutCreateProtocolValidator;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\ProtocolValidatorInterface;

#[CoversClass(CartIdCheckoutCreateProtocolValidator::class)]
final class CartIdCheckoutCreateProtocolValidatorTest extends TestCase
{
    public function testInjectsEmptyLineItemsForCartIdOnlyCheckoutCreate(): void
    {
        $inner = $this->createMock(ProtocolValidatorInterface::class);
        $context = new RequestContext('example.com');
        $inner
            ->expects(self::once())
            ->method('validateRequest')
            ->with('checkout.create', ['cart_id' => 'c1', 'line_items' => []], $context);

        (new CartIdCheckoutCreateProtocolValidator($inner))->validateRequest(
            'checkout.create',
            ['cart_id' => 'c1'],
            $context,
        );
    }

    public function testLeavesPayloadUntouchedWhenLineItemsPresent(): void
    {
        $inner = $this->createMock(ProtocolValidatorInterface::class);
        $context = new RequestContext('example.com');
        $payload = ['line_items' => [['sku' => 'x']]];
        $inner->expects(self::once())->method('validateRequest')->with('checkout.create', $payload, $context);

        (new CartIdCheckoutCreateProtocolValidator($inner))->validateRequest('checkout.create', $payload, $context);
    }

    public function testDoesNotTouchOtherOperations(): void
    {
        $inner = $this->createMock(ProtocolValidatorInterface::class);
        $context = new RequestContext('example.com');
        $payload = ['cart_id' => 'c1'];
        $inner->expects(self::once())->method('validateRequest')->with('cart.create', $payload, $context);

        (new CartIdCheckoutCreateProtocolValidator($inner))->validateRequest('cart.create', $payload, $context);
    }
}
