<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderBodyContextEvent;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\Generator;
use Swag\AgenticCommerce\Content\ProductExport\Provider\AbstractAgenticCommerceProductExportProvider;
use Swag\AgenticCommerce\Content\ProductExport\Provider\AgenticCommerceProductExportProviderRegistry;
use Swag\AgenticCommerce\Content\ProductExport\Subscriber\AgenticCommerceProductExportProviderContextSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
#[CoversClass(AgenticCommerceProductExportProviderContextSubscriber::class)]
class AgenticCommerceProductExportProviderContextSubscriberTest extends TestCase
{
    public function testGetSubscribedEvents(): void
    {
        static::assertSame(
            [ProductExportRenderBodyContextEvent::class => 'extendBodyContext'],
            AgenticCommerceProductExportProviderContextSubscriber::getSubscribedEvents()
        );
    }

    public function testExtendBodyContextReadsProviderFromEntity(): void
    {
        $productExport = $this->createProductExportWithProvider('open-ai');

        $subscriber = new AgenticCommerceProductExportProviderContextSubscriber(
            new AgenticCommerceProductExportProviderRegistry([$this->createProvider()]),
            new RequestStack(),
        );

        $event = $this->dispatch($subscriber, $productExport);

        static::assertInstanceOf(ArrayStruct::class, $event->getContext()['provider']);
        static::assertSame('open-ai', $event->getContext()['provider']->get('name'));
    }

    public function testExtendBodyContextFallsBackToRequestProviderWhenEntityHasNone(): void
    {
        $request = new Request();
        $request->request->set('provider', 'open-ai');

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $subscriber = new AgenticCommerceProductExportProviderContextSubscriber(
            new AgenticCommerceProductExportProviderRegistry([$this->createProvider()]),
            $requestStack,
        );

        $event = $this->dispatch($subscriber, $this->createProductExportWithoutProvider());

        static::assertInstanceOf(ArrayStruct::class, $event->getContext()['provider']);
        static::assertSame('open-ai', $event->getContext()['provider']->get('name'));
    }

    public function testExtendBodyContextDoesNothingWhenNeitherEntityNorRequestHasProvider(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $subscriber = new AgenticCommerceProductExportProviderContextSubscriber(
            new AgenticCommerceProductExportProviderRegistry([$this->createProvider()]),
            $requestStack,
        );

        $event = $this->dispatch($subscriber, $this->createProductExportWithoutProvider());

        static::assertArrayNotHasKey('provider', $event->getContext());
    }

    public function testExtendBodyContextDoesNothingWhenContextIsIncomplete(): void
    {
        $subscriber = new AgenticCommerceProductExportProviderContextSubscriber(
            new AgenticCommerceProductExportProviderRegistry([$this->createProvider()]),
            new RequestStack(),
        );

        $event = new ProductExportRenderBodyContextEvent([
            'productExport' => new ProductExportEntity(),
        ]);

        $subscriber->extendBodyContext($event);

        static::assertSame(['productExport' => $event->getContext()['productExport']], $event->getContext());
    }

    public function testExtendBodyContextDoesNothingWhenProviderIsNotRegistered(): void
    {
        $productExport = $this->createProductExportWithProvider('open-ai');

        $subscriber = new AgenticCommerceProductExportProviderContextSubscriber(
            new AgenticCommerceProductExportProviderRegistry([]),
            new RequestStack(),
        );

        $event = $this->dispatch($subscriber, $productExport);

        static::assertArrayNotHasKey('provider', $event->getContext());
    }

    private function dispatch(
        AgenticCommerceProductExportProviderContextSubscriber $subscriber,
        ProductExportEntity $productExport,
    ): ProductExportRenderBodyContextEvent {
        $event = new ProductExportRenderBodyContextEvent([
            'productExport' => $productExport,
            'context' => $this->createSalesChannelContext(),
        ]);

        $subscriber->extendBodyContext($event);

        return $event;
    }

    private function createProductExportWithProvider(string $provider): ProductExportEntity
    {
        $productExport = $this->createProductExportWithoutProvider();
        $productExport->assign(['provider' => $provider]);

        return $productExport;
    }

    private function createProductExportWithoutProvider(): ProductExportEntity
    {
        $productExport = new ProductExportEntity();
        $productExport->setId(Uuid::randomHex());
        $productExport->setSalesChannelId(Uuid::randomHex());

        return $productExport;
    }

    private function createSalesChannelContext(): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId(Uuid::randomHex());

        return Generator::generateSalesChannelContext(
            baseContext: Context::createDefaultContext(),
            salesChannel: $salesChannel
        );
    }

    private function createProvider(): AbstractAgenticCommerceProductExportProvider
    {
        return new class('open-ai') extends AbstractAgenticCommerceProductExportProvider {
            public function __construct(private readonly string $technicalName)
            {
            }

            public function getTechnicalName(): string
            {
                return $this->technicalName;
            }

            protected function buildProviderContext(
                ProductExportEntity $productExport,
                SalesChannelContext $salesChannelContext,
            ): array {
                return [];
            }
        };
    }
}
