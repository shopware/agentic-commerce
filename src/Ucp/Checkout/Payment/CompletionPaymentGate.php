<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\ProfileBuilderInterface;

/**
 * Refuses a completion unless it carries exactly one payment instrument naming a handler that the
 * business profile advertises for the sales channel.
 *
 * @internal
 */
#[Package('checkout')]
final class CompletionPaymentGate
{
    /**
     * @param \Closure(): ProfileBuilderInterface $profileBuilder
     */
    public function __construct(private readonly \Closure $profileBuilder)
    {
    }

    /**
     * @param list<string> $handlerIds the `handler_id` of every instrument the completion carries
     *
     * @return Message|null the refusal, or null when the completion may go on
     */
    public function refusal(array $handlerIds, RequestContext $context): ?Message
    {
        if (1 !== \count($handlerIds)) {
            return $this->paymentFailed(
                \sprintf('Completing a checkout needs exactly one payment instrument, %d given.', \count($handlerIds)),
                '$.payment.instruments',
            );
        }

        if (!\in_array($handlerIds[0], $this->advertisedHandlerIds($context), true)) {
            return $this->paymentFailed(
                \sprintf('The payment handler "%s" is not one this business advertises.', $handlerIds[0]),
                '$.payment.instruments[0].handler_id',
            );
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function advertisedHandlerIds(RequestContext $context): array
    {
        $runtimeConfiguration = $context->runtimeConfiguration;
        if (null === $runtimeConfiguration) {
            return [];
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

    private function paymentFailed(string $content, string $path): Message
    {
        return new Message('error', $content, 'recoverable', 'payment_failed', $path);
    }
}
