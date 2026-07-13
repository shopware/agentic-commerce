<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductExport\Event\ProductExportProductCriteriaEvent;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Content\ProductExport\Struct\ExportBehavior;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\Content\ProductExport\Subscriber\AgenticCommerceProductExportCriteriaSubscriber;
use Swag\AgenticCommerce\Tests\TestGenerator as Generator;

/**
 * @internal
 */
#[CoversClass(AgenticCommerceProductExportCriteriaSubscriber::class)]
class AgenticCommerceProductExportCriteriaSubscriberTest extends TestCase
{
    public function testSubscribesToProductExportProductCriteriaEvent(): void
    {
        static::assertArrayHasKey(
            ProductExportProductCriteriaEvent::class,
            AgenticCommerceProductExportCriteriaSubscriber::getSubscribedEvents()
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function agenticProviderProvider(): iterable
    {
        yield 'open-ai provider loads characteristics associations' => ['open-ai'];
        yield 'google provider loads characteristics associations' => ['google'];
    }

    #[DataProvider('agenticProviderProvider')]
    public function testAddsAssociationsForAgenticProviders(string $provider): void
    {
        $criteria = new Criteria();

        $this->createSubscriber()->addEssentialCharacteristicsAssociations(
            $this->createEvent($criteria, $provider)
        );

        static::assertTrue($criteria->hasAssociation('featureSet'));
        static::assertTrue($criteria->hasAssociation('properties'));
        static::assertTrue($criteria->getAssociation('properties')->hasAssociation('group'));
    }

    public function testIgnoresNonAgenticProvider(): void
    {
        $criteria = new Criteria();

        $this->createSubscriber()->addEssentialCharacteristicsAssociations(
            $this->createEvent($criteria, 'some-other-feed')
        );

        static::assertFalse($criteria->hasAssociation('featureSet'));
        static::assertFalse($criteria->hasAssociation('properties'));
    }

    public function testIgnoresExportWithoutProvider(): void
    {
        $criteria = new Criteria();

        $this->createSubscriber()->addEssentialCharacteristicsAssociations(
            $this->createEvent($criteria, null)
        );

        static::assertFalse($criteria->hasAssociation('featureSet'));
    }

    private function createSubscriber(): AgenticCommerceProductExportCriteriaSubscriber
    {
        return new AgenticCommerceProductExportCriteriaSubscriber();
    }

    private function createEvent(Criteria $criteria, ?string $provider): ProductExportProductCriteriaEvent
    {
        $productExport = new ProductExportEntity();
        $productExport->setId(Uuid::randomHex());

        if (null !== $provider) {
            $productExport->assign(['provider' => $provider]);
        }

        return new ProductExportProductCriteriaEvent(
            $criteria,
            $productExport,
            new ExportBehavior(),
            $this->createContext()
        );
    }

    private function createContext(): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(Uuid::randomHex());
        $salesChannel->setName('Agentic');

        return Generator::generateSalesChannelContext(
            baseContext: Context::createDefaultContext(),
            salesChannel: $salesChannel
        );
    }
}
