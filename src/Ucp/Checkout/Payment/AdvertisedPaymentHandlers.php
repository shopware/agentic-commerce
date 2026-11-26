<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\ProfileBuilderInterface;

/**
 * The payment handlers the business profile advertises for the sales channel of a request.
 *
 * @internal
 */
#[Package('checkout')]
final class AdvertisedPaymentHandlers
{
    /**
     * @param \Closure(): ProfileBuilderInterface $profileBuilder
     */
    public function __construct(private readonly \Closure $profileBuilder)
    {
    }

    /**
     * @return list<string>
     *
     * @throws UcpConfigException when the request carries no runtime configuration
     */
    public function ids(RequestContext $context): array
    {
        $runtimeConfiguration = $context->runtimeConfiguration;
        if (null === $runtimeConfiguration) {
            throw UcpConfigException::runtimeConfigurationMissing();
        }

        $profile = ($this->profileBuilder)()->build(new ProfileBuildInput(
            $runtimeConfiguration->version,
            $runtimeConfiguration->baseUri,
            $runtimeConfiguration->transports,
            supportedVersions: $runtimeConfiguration->supportedVersions,
            transportEndpoints: $runtimeConfiguration->transportEndpoints,
            tenantIdentifier: $runtimeConfiguration->tenantIdentifier,
            enabledCapabilities: $runtimeConfiguration->enabledCapabilities,
        ));

        return array_map(
            static fn (PaymentHandlerDescriptor $descriptor): string => $descriptor->id,
            array_merge(...array_values($profile->paymentHandlers)),
        );
    }
}
