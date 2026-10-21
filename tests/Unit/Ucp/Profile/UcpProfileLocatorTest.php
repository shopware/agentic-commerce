<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Profile\UcpProfileLocator;

/**
 * @internal
 */
#[CoversClass(UcpProfileLocator::class)]
final class UcpProfileLocatorTest extends TestCase
{
    public function testADomainWithoutAPathPublishesItsProfileAtTheOriginRoot(): void
    {
        static::assertSame('https://shop.example/.well-known/ucp', (new UcpProfileLocator())->profileUrl('https://shop.example'));
        static::assertSame('https://shop.example/.well-known/ucp', (new UcpProfileLocator())->profileUrl('https://shop.example/'));
    }

    public function testADomainWithAPathPublishesNoProfile(): void
    {
        static::assertNull((new UcpProfileLocator())->profileUrl('https://shop.example/de'));
        static::assertNull((new UcpProfileLocator())->profileUrl('https://shop.example/de/'));
    }

    public function testAnEmptyDomainUrlYieldsTheRootRelativeProfilePath(): void
    {
        static::assertSame('/.well-known/ucp', (new UcpProfileLocator())->profileUrl(''));
    }
}
