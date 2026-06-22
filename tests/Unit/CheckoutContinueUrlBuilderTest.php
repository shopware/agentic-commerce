<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Checkout\CheckoutContinueUrlBuilder;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;

/** @internal */
final class CheckoutContinueUrlBuilderTest extends TestCase
{
    #[Test]
    public function testItUrlEncodesTemplateValues(): void
    {
        $builder = new CheckoutContinueUrlBuilder($this->configService(new UcpConfig(
            continueUrlTemplate: 'https://merchant.example/checkout/{checkoutId}?cartId={cartId}&salesChannelId={salesChannelId}',
        )));

        self::assertSame(
            'https://merchant.example/checkout/cart%2Fid%3F1?cartId=cart%2Fid%3F1&salesChannelId=sales%20channel%2Fid',
            $builder->build('cart/id?1', 'sales channel/id'),
        );
    }

    private function configService(UcpConfig $config): UcpConfigService
    {
        $repository = $this->createMock(UcpConfigRepositoryInterface::class);
        $repository->method('find')->willReturn($config);

        return new UcpConfigService($repository, $this->createMock(LegacyConfigStoreInterface::class));
    }
}
