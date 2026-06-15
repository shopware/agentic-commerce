<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Capability\UcpExtensionAvailability;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Profile\CapabilityFilteringProfileContributor;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
final class CapabilityFilteringProfileContributorTest extends TestCase
{
    #[Test]
    public function testItPrunesDiscountExtendsToAdvertisedParents(): void
    {
        $result = $this->contribute([
            UcpCapabilityCatalog::CONFIG_CART,
            UcpCapabilityCatalog::CONFIG_DISCOUNT,
        ], [
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
        $result = $this->contribute([
            UcpCapabilityCatalog::CONFIG_DISCOUNT,
        ], [
            UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT => [
                $this->descriptor(UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT),
            ],
        ]);

        self::assertArrayNotHasKey(UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT, $result);
    }

    /**
     * @param list<string> $enabledCapabilities
     * @param array<string, list<CapabilityDescriptor>> $profileCapabilities
     *
     * @return array<string, list<CapabilityDescriptor>>
     */
    private function contribute(array $enabledCapabilities, array $profileCapabilities): array
    {
        $profile = new PlatformProfile(UcpProtocol::VERSION, [], $profileCapabilities, []);

        return $this->contributor($enabledCapabilities)->contribute(
            $profile,
            new ProfileBuildInput(UcpProtocol::VERSION, 'https://shop.example'),
        )->capabilities;
    }

    private function descriptor(string $name): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            $name,
            UcpProtocol::VERSION,
            'https://ucp.dev/specification/test/',
            'https://ucp.dev/schemas/test.json',
            UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT === $name ? [
                UcpCapabilityCatalog::DESCRIPTOR_CART,
                UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT,
            ] : null,
        );
    }

    /**
     * @param list<string> $enabledCapabilities
     */
    private function contributor(array $enabledCapabilities): CapabilityFilteringProfileContributor
    {
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'SwagAgenticCommerce.config.active' => true,
            'SwagAgenticCommerce.config.enabledCapabilities' => $enabledCapabilities,
            default => null,
        });

        $domainRepository = $this->createMock(EntityRepository::class);
        $domainRepository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'sales_channel_domain',
                0,
                new EntityCollection(),
                null,
                $criteria,
                $context,
            ),
        );

        $paymentHandlerRegistry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $paymentHandlerRegistry->method('all')->willReturn([]);

        return new CapabilityFilteringProfileContributor(
            new SalesChannelDomainResolver($domainRepository),
            new UcpConfigService($this->createMock(UcpConfigRepositoryInterface::class), $legacyStore),
            new ShopwareVersionDetector('6.7.0.0'),
            new UcpExtensionAvailability([], $paymentHandlerRegistry),
        );
    }
}
