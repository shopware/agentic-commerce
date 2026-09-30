<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Seeder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Swag\AgenticCommerce\TestData\Catalogue\BuiltInCatalogue;
use Swag\AgenticCommerce\TestData\Seeder\PayPalSeeder;
use Swag\AgenticCommerce\TestData\TestDataEnvironment;

/**
 * @internal
 */
#[CoversClass(PayPalSeeder::class)]
class PayPalSeederTest extends TestCase
{
    private const SHOP_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';
    private const HEADLESS_ID = '0191bbbbbbbb7000bbbbbbbbbbbbbbbb';
    private const PAYPAL_ID = '0191cccccccc7000cccccccccccccccc';
    private const PAY_LATER_ID = '0191dddddddd7000dddddddddddddddd';
    private const INVOICE_ID = '0191eeeeeeee7000eeeeeeeeeeeeeeee';

    /** @var StaticEntityRepository<SalesChannelCollection> */
    private StaticEntityRepository $salesChannelRepository;

    /** @var StaticEntityRepository<EntityCollection<Entity>> */
    private StaticEntityRepository $salesChannelPaymentMethodRepository;

    private StaticSystemConfigService $systemConfig;

    protected function setUp(): void
    {
        $this->salesChannelRepository = new StaticEntityRepository([]);
        $this->salesChannelPaymentMethodRepository = new StaticEntityRepository([]);
        $this->systemConfig = new StaticSystemConfigService();
    }

    public function testItAssignsOnlyUnassignedMethodsAndRecordsThem(): void
    {
        $this->salesChannelRepository = new StaticEntityRepository([new SalesChannelCollection([
            self::salesChannel(self::SHOP_ID, 'Shop', self::INVOICE_ID, [self::INVOICE_ID, self::PAYPAL_ID]),
            self::salesChannel(self::HEADLESS_ID, 'Headless', self::PAYPAL_ID, [self::PAYPAL_ID, self::PAY_LATER_ID]),
        ])]);
        $this->systemConfig->set('SwagPayPal.settings.clientId', 'live-client');

        $reportLines = $this->seeder()->create([self::SHOP_ID, self::HEADLESS_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        static::assertSame([['id' => self::SHOP_ID, 'paymentMethods' => [['id' => self::PAY_LATER_ID]]]], $this->salesChannelRepository->updates[0]);
        static::assertSame([self::SHOP_ID => [self::PAY_LATER_ID]], $this->systemConfig->get(PayPalSeeder::ASSIGNMENTS_CONFIG_KEY));
        static::assertSame(['Shop: added Pay Later', 'Headless: all PayPal methods were already assigned'], $reportLines);
    }

    public function testItWarnsWhenPayPalHasNoCredentials(): void
    {
        $this->salesChannelRepository = new StaticEntityRepository([new SalesChannelCollection([
            self::salesChannel(self::SHOP_ID, 'Shop', self::INVOICE_ID, [self::INVOICE_ID]),
        ])]);

        $reportLines = $this->seeder()->create([self::SHOP_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        static::assertContains('Shop: PayPal has no API credentials, so checkout hides its methods', $reportLines);
    }

    public function testItAssignsNothingWithoutActivePayPalMethods(): void
    {
        $reportLines = $this->seeder(new PaymentMethodCollection())->create([self::SHOP_ID], BuiltInCatalogue::pickedProducts(), Context::createDefaultContext());

        static::assertSame(['SwagPayPal has no active payment method, nothing assigned.'], $reportLines);
        static::assertSame([], $this->salesChannelRepository->updates);
        static::assertFalse($this->seeder()->exists(Context::createDefaultContext()));
    }

    public function testRemoveDeletesRecordedAssignmentsExceptTheDefaultMethod(): void
    {
        $this->systemConfig->set(PayPalSeeder::ASSIGNMENTS_CONFIG_KEY, [self::SHOP_ID => [self::PAYPAL_ID, self::PAY_LATER_ID]]);
        $this->salesChannelRepository = new StaticEntityRepository([new SalesChannelCollection([
            self::salesChannel(self::SHOP_ID, 'Shop', self::PAYPAL_ID, [self::PAYPAL_ID, self::PAY_LATER_ID]),
        ])]);
        $seeder = $this->seeder();

        static::assertTrue($seeder->exists(Context::createDefaultContext()));
        static::assertTrue($seeder->remove(Context::createDefaultContext()));
        static::assertSame([[['salesChannelId' => self::SHOP_ID, 'paymentMethodId' => self::PAY_LATER_ID]]], $this->salesChannelPaymentMethodRepository->deletes);
        static::assertNull($this->systemConfig->get(PayPalSeeder::ASSIGNMENTS_CONFIG_KEY));
        static::assertFalse($seeder->remove(Context::createDefaultContext()));
    }

    private function seeder(?PaymentMethodCollection $payPalMethods = null): PayPalSeeder
    {
        /** @var StaticEntityRepository<PaymentMethodCollection> $paymentMethodRepository */
        $paymentMethodRepository = new StaticEntityRepository([$payPalMethods ?? new PaymentMethodCollection([
            self::paymentMethod(self::PAYPAL_ID, 'PayPal'),
            self::paymentMethod(self::PAY_LATER_ID, 'Pay Later'),
        ])]);

        return new PayPalSeeder(
            $paymentMethodRepository,
            $this->salesChannelRepository,
            $this->salesChannelPaymentMethodRepository,
            $this->systemConfig,
            new TestDataEnvironment([], $this->createMock(DefinitionInstanceRegistry::class), null),
        );
    }

    private static function paymentMethod(string $id, string $name): PaymentMethodEntity
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId($id);
        $paymentMethod->setUniqueIdentifier($id);
        $paymentMethod->setName($name);

        return $paymentMethod;
    }

    /**
     * @param list<string> $assignedPaymentMethodIds
     */
    private static function salesChannel(string $id, string $name, string $defaultPaymentMethodId, array $assignedPaymentMethodIds): SalesChannelEntity
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId($id);
        $salesChannel->setUniqueIdentifier($id);
        $salesChannel->setName($name);
        $salesChannel->setPaymentMethodId($defaultPaymentMethodId);
        $salesChannel->setPaymentMethods(new PaymentMethodCollection(array_map(
            static fn (string $paymentMethodId): PaymentMethodEntity => self::paymentMethod($paymentMethodId, $paymentMethodId),
            $assignedPaymentMethodIds,
        )));

        return $salesChannel;
    }
}
