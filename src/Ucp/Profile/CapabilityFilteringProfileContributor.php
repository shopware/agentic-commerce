<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Profile;

use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\Capability\UcpExtensionAvailability;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Ucp\Sdk\Contract\ProfileContributorInterface;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\Profile\ServiceEndpoint;

final readonly class CapabilityFilteringProfileContributor implements ProfileContributorInterface
{
    public function __construct(
        private SalesChannelDomainResolver $domainResolver,
        private UcpConfigService $configService,
        private ShopwareVersionDetector $versionDetector,
        private UcpExtensionAvailability $extensionAvailability,
    ) {
    }

    public function contribute(PlatformProfile $profile, ProfileBuildInput $input): PlatformProfile
    {
        $resolution = $this->domainResolver->resolveByBaseUri($input->baseUri);
        $config = $this->configService->getConfig($resolution?->salesChannelId);
        $enabledDescriptors = $config->runtimeEnabledCapabilityDescriptors();
        if (!$this->extensionAvailability->supportsIdentityLinking()) {
            $enabledDescriptors = array_values(array_diff($enabledDescriptors, [UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING]));
        }

        if (!$this->extensionAvailability->supportsPaymentTokenization()) {
            $enabledDescriptors = array_values(array_diff($enabledDescriptors, [UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION]));
        }

        $enabledTransports = array_map(
            static fn (Transport $transport): string => $transport->value,
            $config->runtimeTransports($this->versionDetector->supportsStoreApiMcp()),
        );

        $capabilities = array_intersect_key($profile->capabilities, array_flip($enabledDescriptors));
        $capabilities = $this->withPrunedDiscountExtension($capabilities);
        $services = $profile->services;

        if (!$config->active) {
            $services = [];
        } else {
            $services = [
                'dev.ucp.shopping' => array_values(array_filter(
                    $profile->services['dev.ucp.shopping'] ?? [],
                    static fn (ServiceEndpoint $endpoint): bool => \in_array($endpoint->transport->value, $enabledTransports, true),
                )),
            ];
        }

        return new PlatformProfile(
            $profile->version,
            $services,
            $capabilities,
            \in_array(UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION, $enabledDescriptors, true) ? $profile->paymentHandlers : [],
            $profile->signingKeys,
            $profile->supportedVersions,
        );
    }

    /**
     * @param array<string, list<CapabilityDescriptor>> $capabilities
     *
     * @return array<string, list<CapabilityDescriptor>>
     */
    private function withPrunedDiscountExtension(array $capabilities): array
    {
        if (!isset($capabilities[UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT])) {
            return $capabilities;
        }

        $extends = array_values(array_intersect([
            UcpCapabilityCatalog::DESCRIPTOR_CART,
            UcpCapabilityCatalog::DESCRIPTOR_CHECKOUT,
        ], array_keys($capabilities)));

        if ($extends === []) {
            unset($capabilities[UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT]);

            return $capabilities;
        }

        $capabilities[UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT] = array_map(
            static fn (CapabilityDescriptor $descriptor): CapabilityDescriptor => new CapabilityDescriptor(
                $descriptor->name,
                $descriptor->version,
                $descriptor->specUrl,
                $descriptor->schemaUrl,
                $extends,
                $descriptor->config,
            ),
            $capabilities[UcpCapabilityCatalog::DESCRIPTOR_DISCOUNT],
        );

        return $capabilities;
    }
}
