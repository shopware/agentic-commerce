<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClassification;
use Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures\StaticSalesChannelTypeResolver;
use Swag\AgenticCommerce\Tests\Unit\Ucp\Admin\Fixtures\RecordingUcpActivationWriter;
use Swag\AgenticCommerce\Ucp\Admin\SalesChannelPreparationService;

/** @internal */
#[CoversClass(SalesChannelPreparationService::class)]
final class SalesChannelPreparationServiceTest extends TestCase
{
    private const STOREFRONT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
    private const HEADLESS = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2';
    private const AGENTIC = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa3';
    private const PRODUCT_COMPARISON = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa4';

    public function testItActivatesOnlyTransactionalSalesChannels(): void
    {
        $writer = $this->createWriter();
        $service = new SalesChannelPreparationService($writer, $this->createResolver());

        $prepared = $service->prepare([
            self::STOREFRONT,
            self::AGENTIC,
            self::HEADLESS,
            self::PRODUCT_COMPARISON,
        ]);

        static::assertSame([self::STOREFRONT, self::HEADLESS], $prepared);
        static::assertSame([self::STOREFRONT, self::HEADLESS], $writer->activated);
    }

    public function testItSkipsUnknownAndNonTransactionalIdsWithoutActivatingThem(): void
    {
        $writer = $this->createWriter();
        $service = new SalesChannelPreparationService($writer, $this->createResolver());

        $prepared = $service->prepare([self::AGENTIC, self::PRODUCT_COMPARISON, 'unknown-id']);

        static::assertSame([], $prepared);
        static::assertSame([], $writer->activated);
    }

    public function testItReturnsEmptyForAnEmptyList(): void
    {
        $writer = $this->createWriter();
        $service = new SalesChannelPreparationService($writer, $this->createResolver());

        static::assertSame([], $service->prepare([]));
        static::assertSame([], $writer->activated);
    }

    private function createResolver(): StaticSalesChannelTypeResolver
    {
        return new StaticSalesChannelTypeResolver(SalesChannelTypeClassification::Other, [
            self::STOREFRONT => SalesChannelTypeClassification::Storefront,
            self::HEADLESS => SalesChannelTypeClassification::Headless,
            self::AGENTIC => SalesChannelTypeClassification::AgenticCommerce,
            self::PRODUCT_COMPARISON => SalesChannelTypeClassification::ProductComparison,
        ]);
    }

    private function createWriter(): RecordingUcpActivationWriter
    {
        return new RecordingUcpActivationWriter();
    }
}
