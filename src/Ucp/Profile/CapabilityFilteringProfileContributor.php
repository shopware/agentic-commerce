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

/** @internal */
final class CapabilityFilteringProfileContributor implements ProfileContributorInterface
{
    public function __construct(
        private readonly SalesChannelDomainResolver $domainResolver,
        private readonly UcpConfigService $configService,
        private readonly ShopwareVersionDetector $versionDetector,
        private readonly UcpExtensionAvailability $extensionAvailability,
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

        // `dev.ucp.shopping.payment_tokenization` is not a capability any release defines. At
        // 2026-08-25 tokenization is a payment *handler* concern -- handlers/tokenization
        // /openapi.json -- so publishing it advertised something no peer can negotiate on,
        // the same defect the catalog ids had. The switch itself stays: it is what decides
        // whether this business publishes payment handlers at all, which is a real question
        // with a real answer. It just is not a capability id.
        unset($capabilities[UcpCapabilityCatalog::DESCRIPTOR_PAYMENT_TOKENIZATION]);
        $capabilities = $this->withAdditionalDescriptors($capabilities, $enabledDescriptors);
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

        if ([] === $extends) {
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
    /**
     * Publish the descriptors a single capability class cannot report.
     *
     * `CapabilityInterface::describe()` returns one descriptor, so the SDK builds one entry
     * per registered capability -- but the catalog capability implements two of the
     * specification's capabilities, search and lookup, and a business that advertises only
     * one of them cannot negotiate the other. The filter above can only remove, so the
     * second name is added here, from the same catalog the descriptors themselves come from.
     *
     * @param array<string, list<CapabilityDescriptor>> $capabilities
     * @param list<string>                              $enabledDescriptors
     *
     * @return array<string, list<CapabilityDescriptor>>
     */
    private function withAdditionalDescriptors(array $capabilities, array $enabledDescriptors): array
    {
        foreach (UcpCapabilityCatalog::allConfigKeys() as $configKey) {
            foreach (UcpCapabilityCatalog::descriptors($configKey) as $descriptor) {
                if (isset($capabilities[$descriptor->name])) {
                    continue;
                }

                if (!\in_array($descriptor->name, $enabledDescriptors, true)) {
                    continue;
                }

                // Only alongside a sibling the SDK did build. Without that check this would
                // advertise a capability whose class is not registered at all, which is the
                // mistake it exists to correct rather than repeat.
                if (!$this->hasSiblingDescriptor($capabilities, $configKey)) {
                    continue;
                }

                $capabilities[$descriptor->name] = [$descriptor];
            }
        }

        return $capabilities;
    }

    /**
     * @param array<string, list<CapabilityDescriptor>> $capabilities
     */
    private function hasSiblingDescriptor(array $capabilities, string $configKey): bool
    {
        foreach (UcpCapabilityCatalog::descriptorNamesForConfigKey($configKey) as $name) {
            if (isset($capabilities[$name])) {
                return true;
            }
        }

        return false;
    }
}
