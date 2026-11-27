<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Negotiation;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Checkout\Payment\AdvertisedPaymentHandlers;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityNegotiatorInterface;

/**
 * The SDK negotiates against every registered payment handler, so a handler the profile of the sales
 * channel leaves out would still show in the payment handlers of a checkout response.
 *
 * @internal
 */
#[Package('checkout')]
final class AdvertisedPaymentHandlerNegotiator implements CapabilityNegotiatorInterface
{
    public function __construct(
        private readonly CapabilityNegotiatorInterface $inner,
        private readonly AdvertisedPaymentHandlers $advertisedHandlers,
    ) {
    }

    public function negotiate(?PlatformProfile $platformProfile, RequestContext $context): NegotiatedCapabilities
    {
        $negotiated = $this->inner->negotiate($platformProfile, $context);
        if ([] === $negotiated->paymentHandlerIds) {
            return $negotiated;
        }

        $advertisedHandlerIds = $this->advertisedHandlers->ids($context);

        $paymentHandlers = [];
        foreach ($negotiated->paymentHandlers as $name => $descriptors) {
            $advertisedDescriptors = array_values(array_filter(
                $descriptors,
                static fn (PaymentHandlerDescriptor $descriptor): bool => \in_array($descriptor->id, $advertisedHandlerIds, true),
            ));
            if ([] !== $advertisedDescriptors) {
                $paymentHandlers[$name] = $advertisedDescriptors;
            }
        }

        return new NegotiatedCapabilities(
            $negotiated->capabilities,
            array_values(array_intersect($negotiated->paymentHandlerIds, $advertisedHandlerIds)),
            $negotiated->operationCapabilityMap,
            $paymentHandlers,
        );
    }
}
