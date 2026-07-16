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
use Swag\AgenticCommerce\Ucp\Ap2\Ap2MandateClaimsVerifierInterface;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Capability\UcpExtensionAvailability;
use Swag\AgenticCommerce\Ucp\Config\LegacyConfigStoreInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigRepositoryInterface;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Payment\PaymentAuthorizerInterface;
use Swag\AgenticCommerce\Ucp\Profile\CapabilityFilteringProfileContributor;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Contract\PaymentHandlerInterface;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
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

    #[Test]
    public function testItFiltersAp2WhenVerifierSupportIsUnavailable(): void
    {
        $result = $this->contribute([
            UcpCapabilityCatalog::CONFIG_CHECKOUT,
            UcpCapabilityCatalog::CONFIG_AP2_MANDATE,
        ], [
            UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT => [$this->descriptor(UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT)],
            UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE => [$this->descriptor(UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE)],
        ]);

        self::assertArrayHasKey(UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT, $result);
        self::assertArrayNotHasKey(UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE, $result);
    }

    #[Test]
    public function testItAdvertisesAp2WhenAVerifierIsRegistered(): void
    {
        $result = $this->contribute([
            UcpCapabilityCatalog::CONFIG_CHECKOUT,
            UcpCapabilityCatalog::CONFIG_AP2_MANDATE,
        ], [
            UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT => [$this->descriptor(UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT)],
            UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE => [$this->descriptor(UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE)],
        ], [$this->createMock(Ap2MandateClaimsVerifierInterface::class)]);

        self::assertArrayHasKey(UcpCapabilityCatalog::DESCRIPTOR_AP2_MANDATE, $result);
    }

    #[Test]
    public function testItDropsNonTokenizingHandlersByDefault(): void
    {
        $result = $this->contributePaymentHandlers(
            [UcpCapabilityCatalog::CONFIG_CHECKOUT],
            ['com.shopware.invoice' => [$this->paymentHandlerDescriptor('com.shopware.invoice')]],
            [$this->paymentHandler('com.shopware.invoice', false)],
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function testItAdvertisesDelegatedHandlersWhenTheSalesChannelOptsIn(): void
    {
        $result = $this->contributePaymentHandlers(
            [UcpCapabilityCatalog::CONFIG_CHECKOUT],
            ['com.shopware.x402' => [$this->paymentHandlerDescriptor('com.shopware.x402')]],
            [$this->paymentHandler('com.shopware.x402', false)],
            advertiseDelegatedPaymentHandlers: true,
            paymentAuthorizers: [$this->paymentAuthorizer('com.shopware.x402')],
        );

        self::assertArrayHasKey('com.shopware.x402', $result);
    }

    #[Test]
    public function testItDropsDelegatedHandlersWithoutAPaymentAuthorizer(): void
    {
        $result = $this->contributePaymentHandlers(
            [UcpCapabilityCatalog::CONFIG_CHECKOUT],
            ['com.shopware.x402' => [$this->paymentHandlerDescriptor('com.shopware.x402')]],
            [$this->paymentHandler('com.shopware.x402', false)],
            advertiseDelegatedPaymentHandlers: true,
        );

        self::assertSame([], $result, 'A delegated handler no authorizer can complete must not be advertised.');
    }

    #[Test]
    public function testTokenizingHandlersStillRequireThePaymentTokenizationCapability(): void
    {
        $result = $this->contributePaymentHandlers(
            [UcpCapabilityCatalog::CONFIG_CHECKOUT],
            ['com.example.tokenizer' => [$this->paymentHandlerDescriptor('com.example.tokenizer')]],
            [$this->paymentHandler('com.example.tokenizer', true)],
            advertiseDelegatedPaymentHandlers: true,
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function testTokenizingHandlersAreAdvertisedWithTheCapabilityEnabled(): void
    {
        $result = $this->contributePaymentHandlers(
            [UcpCapabilityCatalog::CONFIG_CHECKOUT, UcpCapabilityCatalog::CONFIG_PAYMENT_TOKENIZATION],
            ['com.example.tokenizer' => [$this->paymentHandlerDescriptor('com.example.tokenizer')]],
            [$this->paymentHandler('com.example.tokenizer', true)],
        );

        self::assertArrayHasKey('com.example.tokenizer', $result);
    }

    /**
     * @param list<string>                                  $enabledCapabilities
     * @param array<string, list<PaymentHandlerDescriptor>> $paymentHandlers
     * @param list<PaymentHandlerInterface>                 $registeredHandlers
     * @param list<PaymentAuthorizerInterface>              $paymentAuthorizers
     *
     * @return array<string, list<PaymentHandlerDescriptor>>
     */
    private function contributePaymentHandlers(
        array $enabledCapabilities,
        array $paymentHandlers,
        array $registeredHandlers,
        bool $advertiseDelegatedPaymentHandlers = false,
        array $paymentAuthorizers = [],
    ): array {
        $profile = new PlatformProfile(UcpProtocol::VERSION, [], [], $paymentHandlers);

        return $this->contributor(
            $enabledCapabilities,
            [],
            $registeredHandlers,
            $advertiseDelegatedPaymentHandlers,
            $paymentAuthorizers,
        )->contribute(
            $profile,
            new ProfileBuildInput(UcpProtocol::VERSION, 'https://shop.example'),
        )->paymentHandlers;
    }

    private function paymentAuthorizer(string $handlerId): PaymentAuthorizerInterface
    {
        $authorizer = $this->createMock(PaymentAuthorizerInterface::class);
        $authorizer->method('supports')->willReturnCallback(
            static fn (string $id): bool => $id === $handlerId,
        );

        return $authorizer;
    }

    private function paymentHandler(string $id, bool $supportsTokenization): PaymentHandlerInterface
    {
        $handler = $this->createMock(PaymentHandlerInterface::class);
        $handler->method('id')->willReturn($id);
        $handler->method('supportsTokenization')->willReturn($supportsTokenization);

        return $handler;
    }

    private function paymentHandlerDescriptor(string $id): PaymentHandlerDescriptor
    {
        return new PaymentHandlerDescriptor(
            $id,
            $id,
            UcpProtocol::VERSION,
            'https://ucp.dev/specification/test/',
            'https://ucp.dev/schemas/test.json',
            [],
        );
    }

    /**
     * @param list<string>                              $enabledCapabilities
     * @param array<string, list<CapabilityDescriptor>> $profileCapabilities
     * @param list<Ap2MandateClaimsVerifierInterface>   $ap2Verifiers
     *
     * @return array<string, list<CapabilityDescriptor>>
     */
    private function contribute(array $enabledCapabilities, array $profileCapabilities, array $ap2Verifiers = []): array
    {
        $profile = new PlatformProfile(UcpProtocol::VERSION, [], $profileCapabilities, []);

        return $this->contributor($enabledCapabilities, $ap2Verifiers)->contribute(
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
     * @param list<string>                            $enabledCapabilities
     * @param list<Ap2MandateClaimsVerifierInterface> $ap2Verifiers
     * @param list<PaymentHandlerInterface>           $registeredHandlers
     * @param list<PaymentAuthorizerInterface>        $paymentAuthorizers
     */
    private function contributor(
        array $enabledCapabilities,
        array $ap2Verifiers = [],
        array $registeredHandlers = [],
        bool $advertiseDelegatedPaymentHandlers = false,
        array $paymentAuthorizers = [],
    ): CapabilityFilteringProfileContributor {
        $legacyStore = $this->createMock(LegacyConfigStoreInterface::class);
        $legacyStore->method('get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'SwagAgenticCommerce.config.active' => true,
            'SwagAgenticCommerce.config.enabledCapabilities' => $enabledCapabilities,
            'SwagAgenticCommerce.config.advertiseDelegatedPaymentHandlers' => $advertiseDelegatedPaymentHandlers,
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

        $handlersById = [];
        foreach ($registeredHandlers as $registeredHandler) {
            $handlersById[$registeredHandler->id()] = $registeredHandler;
        }

        $paymentHandlerRegistry = $this->createMock(PaymentHandlerRegistryInterface::class);
        $paymentHandlerRegistry->method('all')->willReturn($registeredHandlers);
        $paymentHandlerRegistry->method('find')->willReturnCallback(
            static fn (string $name): ?PaymentHandlerInterface => $handlersById[$name] ?? null,
        );

        return new CapabilityFilteringProfileContributor(
            new SalesChannelDomainResolver($domainRepository),
            new UcpConfigService($this->createMock(UcpConfigRepositoryInterface::class), $legacyStore),
            new ShopwareVersionDetector('6.7.0.0'),
            new UcpExtensionAvailability([], $paymentHandlerRegistry, $ap2Verifiers, $paymentAuthorizers),
        );
    }
}
