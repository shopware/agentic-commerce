<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\Content\ProductExport\Provider\AbstractAgenticCommerceProductExportProvider;

/**
 * @internal
 */
#[CoversClass(AbstractAgenticCommerceProductExportProvider::class)]
class AbstractAgenticCommerceProductExportProviderTest extends TestCase
{
    public function testExtendRenderContextAddsProviderStruct(): void
    {
        $provider = $this->createProvider();

        $agenticChannel = new SalesChannelEntity();
        $agenticChannel->setConfiguration(['affiliateCode' => 'aff-1', 'campaignCode' => 'camp-1']);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('channel-id');

        $productExport = new ProductExportEntity();
        $productExport->setSalesChannelId('agentic-channel-id');
        $productExport->setSalesChannel($agenticChannel);

        $result = $provider->extendRenderContext($productExport, $context, []);

        static::assertArrayHasKey('provider', $result);
        static::assertInstanceOf(ArrayStruct::class, $result['provider']);
    }

    public function testExtendRenderContextUsesOwnTrackingCodes(): void
    {
        $provider = $this->createProvider(['extra' => 'value']);

        $agenticChannel = new SalesChannelEntity();
        $agenticChannel->setConfiguration([
            'affiliateCode' => 'affiliate-1',
            'campaignCode' => 'campaign-1',
        ]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('channel-id');

        $productExport = new ProductExportEntity();
        $productExport->setSalesChannelId('agentic-channel-id');
        $productExport->setSalesChannel($agenticChannel);

        $result = $provider->extendRenderContext($productExport, $context, []);

        $providerStruct = $result['provider'];
        static::assertInstanceOf(ArrayStruct::class, $providerStruct);
        static::assertSame('affiliate-1', $providerStruct->get('affiliateCode'));
        static::assertSame('campaign-1', $providerStruct->get('campaignCode'));
        static::assertSame('value', $providerStruct->get('extra'));
    }

    public function testExtendRenderContextWithNoConfiguration(): void
    {
        $provider = $this->createProvider();

        $agenticChannel = new SalesChannelEntity();
        $agenticChannel->setConfiguration([]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('channel-id');

        $productExport = new ProductExportEntity();
        $productExport->setSalesChannelId('agentic-channel-id');
        $productExport->setSalesChannel($agenticChannel);

        $result = $provider->extendRenderContext($productExport, $context, []);

        $providerStruct = $result['provider'];
        static::assertInstanceOf(ArrayStruct::class, $providerStruct);
        static::assertNull($providerStruct->get('affiliateCode'));
        static::assertNull($providerStruct->get('campaignCode'));
    }

    public function testExtendRenderContextIncludesReferringSalesChannelAndName(): void
    {
        $provider = $this->createProvider();

        $agenticChannel = new SalesChannelEntity();
        $agenticChannel->setConfiguration([]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('channel-id');

        $productExport = new ProductExportEntity();
        $productExport->setSalesChannelId('agentic-channel-id');
        $productExport->setSalesChannel($agenticChannel);

        $result = $provider->extendRenderContext($productExport, $context, []);

        $providerStruct = $result['provider'];
        static::assertInstanceOf(ArrayStruct::class, $providerStruct);
        static::assertSame('test-provider', $providerStruct->get('name'));
        static::assertSame(
            'agentic-channel-id',
            $providerStruct->get(AbstractAgenticCommerceProductExportProvider::REFERRING_SALES_CHANNEL_QUERY_PARAM)
        );
    }

    public function testExtendRenderContextMergesWithExistingContext(): void
    {
        $provider = $this->createProvider();

        $agenticChannel = new SalesChannelEntity();
        $agenticChannel->setConfiguration([]);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('channel-id');

        $existing = ['key' => 'value'];
        $productExport = new ProductExportEntity();
        $productExport->setSalesChannelId('agentic-channel-id');
        $productExport->setSalesChannel($agenticChannel);

        $result = $provider->extendRenderContext($productExport, $context, $existing);

        static::assertArrayHasKey('key', $result);
        static::assertArrayHasKey('provider', $result);
    }

    public function testExtendRenderContextWithNullSalesChannelConfiguration(): void
    {
        $provider = $this->createProvider();

        $agenticChannel = new SalesChannelEntity();
        // configuration not set (null)

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('channel-id');

        $productExport = new ProductExportEntity();
        $productExport->setSalesChannelId('agentic-channel-id');
        $productExport->setSalesChannel($agenticChannel);

        $result = $provider->extendRenderContext($productExport, $context, []);

        $providerStruct = $result['provider'];
        static::assertInstanceOf(ArrayStruct::class, $providerStruct);
        static::assertNull($providerStruct->get('affiliateCode'));
        static::assertNull($providerStruct->get('campaignCode'));
    }

    /**
     * @param array<string, mixed> $extraProviderContext
     */
    private function createProvider(array $extraProviderContext = []): AbstractAgenticCommerceProductExportProvider
    {
        return new class($extraProviderContext) extends AbstractAgenticCommerceProductExportProvider {
            /**
             * @param array<string, mixed> $extra
             */
            public function __construct(private readonly array $extra = [])
            {
            }

            public function getTechnicalName(): string
            {
                return 'test-provider';
            }

            protected function buildProviderContext(
                ProductExportEntity $productExport,
                SalesChannelContext $salesChannelContext,
            ): array {
                return $this->extra;
            }
        };
    }
}
