<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Enum\UcpProtocolVersion;

/**
 * The plugin advertises the UCP version the linked SDK serves, and nothing else.
 *
 * `UcpProtocol::VERSION` is deliberately a literal rather than a read of
 * `UcpProtocolVersion::current()`. Deriving it would mean an SDK release that moves to a new
 * spec date silently changes what this plugin advertises while `ShopwareDataMapper` still
 * emits the previous shapes. A failing test is the right way for that to surface; a shop
 * that quietly claims a version it does not speak is not. When this fails after an SDK bump,
 * the mapper and the capability catalog need the review, not this assertion.
 *
 * @internal
 */
#[CoversClass(UcpProtocol::class)]
final class UcpProtocolVersionGuardTest extends TestCase
{
    #[Test]
    public function testThePluginAdvertisesTheVersionTheLinkedSdkServes(): void
    {
        self::assertSame(
            UcpProtocolVersion::current()->value,
            UcpProtocol::VERSION,
            \sprintf(
                'The linked SDK serves UCP %s but the plugin advertises %s. Review ShopwareDataMapper and UcpCapabilityCatalog against the new spec date, then update UcpProtocol::VERSION.',
                UcpProtocolVersion::current()->value,
                UcpProtocol::VERSION,
            ),
        );
    }
}
