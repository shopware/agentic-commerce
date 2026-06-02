<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Profile\CapabilityFilteringProfileContributor;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

/** @internal */
final class CapabilityFilteringProfileContributorTest extends TestCase
{
    #[Test]
    public function testItPrunesDiscountExtendsToAdvertisedParents(): void
    {
        $result = $this->withPrunedDiscountExtension([
            UcpCapabilityCatalog::DESCRIPTOR_CART => [
                $this->descriptor(UcpCapabilityCatalog::DESCRIPTOR_CART),
            ],
            UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT => [
                $this->descriptor(UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT),
            ],
        ]);

        self::assertSame(
            [UcpCapabilityCatalog::DESCRIPTOR_CART],
            $result[UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT][0]->extends,
        );
    }

    #[Test]
    public function testItDropsDiscountWhenNoParentCapabilityIsAdvertised(): void
    {
        $result = $this->withPrunedDiscountExtension([
            UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT => [
                $this->descriptor(UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT),
            ],
        ]);

        self::assertArrayNotHasKey(UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT, $result);
    }

    /**
     * @param array<string, list<CapabilityDescriptor>> $capabilities
     *
     * @return array<string, list<CapabilityDescriptor>>
     */
    private function withPrunedDiscountExtension(array $capabilities): array
    {
        $reflection = new \ReflectionClass(CapabilityFilteringProfileContributor::class);
        $method = $reflection->getMethod('withPrunedDiscountExtension');

        /** @var array<string, list<CapabilityDescriptor>> $result */
        $result = $method->invoke($reflection->newInstanceWithoutConstructor(), $capabilities);

        return $result;
    }

    private function descriptor(string $name): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            $name,
            UcpProtocol::VERSION,
            'https://ucp.dev/specification/test/',
            'https://ucp.dev/schemas/test.json',
            $name === UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT ? [
                UcpCapabilityCatalog::DESCRIPTOR_CART,
                UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT,
            ] : null,
        );
    }
}
