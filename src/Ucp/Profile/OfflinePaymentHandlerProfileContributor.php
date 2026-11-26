<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Profile;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Payment\ShopwareOfflinePaymentHandler;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Ucp\Sdk\Contract\ProfileContributorInterface;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/**
 * Drops the plugin's offline handlers whose payment method the sales channel of the profile does not offer.
 *
 * @internal
 */
#[Package('checkout')]
final class OfflinePaymentHandlerProfileContributor implements ProfileContributorInterface
{
    public function __construct(
        private readonly PaymentHandlerRegistryInterface $handlerRegistry,
        private readonly SalesChannelDomainResolver $domainResolver,
    ) {
    }

    public function contribute(PlatformProfile $profile, ProfileBuildInput $input): PlatformProfile
    {
        $salesChannelId = $this->domainResolver->resolveByBaseUri($input->baseUri)?->salesChannelId;

        $unofferedHandlerIds = [];
        foreach ($this->handlerRegistry->all() as $handler) {
            if (!$handler instanceof ShopwareOfflinePaymentHandler) {
                continue;
            }

            if (null === $salesChannelId || null === $handler->paymentMethodId($salesChannelId)) {
                $unofferedHandlerIds[] = $handler->id();
            }
        }

        $paymentHandlers = [];
        foreach ($profile->paymentHandlers as $name => $descriptors) {
            $offeredDescriptors = array_values(array_filter(
                $descriptors,
                static fn (PaymentHandlerDescriptor $descriptor): bool => !\in_array($descriptor->id, $unofferedHandlerIds, true),
            ));
            if ([] !== $offeredDescriptors) {
                $paymentHandlers[$name] = $offeredDescriptors;
            }
        }

        return new PlatformProfile(
            version: $profile->version,
            services: $profile->services,
            capabilities: $profile->capabilities,
            paymentHandlers: $paymentHandlers,
            signingKeys: $profile->signingKeys,
            supportedVersions: $profile->supportedVersions,
        );
    }
}
