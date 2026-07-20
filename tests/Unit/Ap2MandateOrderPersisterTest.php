<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2MandateOrderPersister;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2VerifiedMandateRegistry;

/** @internal */
final class Ap2MandateOrderPersisterTest extends TestCase
{
    #[Test]
    public function testItWritesTheVerifiedMandateToOrderCustomFields(): void
    {
        $registry = new Ap2VerifiedMandateRegistry();
        $registry->record('checkout-1', 'mandate-token', [
            'checkout_id' => 'checkout-1',
            'total' => ['amount' => 129900, 'currency' => 'EUR'],
        ]);

        $written = null;
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects(static::once())
            ->method('update')
            ->willReturnCallback(function (array $payload) use (&$written) {
                $written = $payload;

                return $this->createStub(\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent::class);
            });

        $persister = new Ap2MandateOrderPersister($registry, $orderRepository);
        $persister->persist('checkout-1', 'order-1', Context::createDefaultContext());

        static::assertNotNull($written);
        static::assertSame('order-1', $written[0]['id']);

        $customFields = $written[0]['customFields'];
        static::assertSame('mandate-token', $customFields[Ap2MandateOrderPersister::CUSTOM_FIELD_MANDATE]);
        static::assertSame(
            [
                'checkout_id' => 'checkout-1',
                'total' => ['amount' => 129900, 'currency' => 'EUR'],
            ],
            json_decode($customFields[Ap2MandateOrderPersister::CUSTOM_FIELD_CLAIMS], true, 512, \JSON_THROW_ON_ERROR),
        );
        static::assertNotFalse(\DateTimeImmutable::createFromFormat(
            \DATE_ATOM,
            $customFields[Ap2MandateOrderPersister::CUSTOM_FIELD_VERIFIED_AT],
        ));
    }

    #[Test]
    public function testItDoesNothingWithoutAVerifiedMandate(): void
    {
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects(static::never())->method('update');

        $persister = new Ap2MandateOrderPersister(new Ap2VerifiedMandateRegistry(), $orderRepository);
        $persister->persist('checkout-1', 'order-1', Context::createDefaultContext());
    }
}
