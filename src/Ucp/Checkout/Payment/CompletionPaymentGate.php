<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
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
    public function __construct(
        private readonly \Closure $profileBuilder,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param list<string> $handlerIds the `handler_id` of every instrument the completion carries
     *
     * @return Message|null the refusal, or null when the completion may go on
     *
     * @throws UcpConfigException when the instrument count is right but the request carries no runtime configuration
     */
    public function refusal(array $handlerIds, RequestContext $context): ?Message
    {
        if (1 !== \count($handlerIds)) {
            $this->logger?->warning(
                'A UCP checkout completion was refused: it must carry exactly one payment instrument.',
                [
                    'handler_ids' => $handlerIds,
                    'instrument_count' => \count($handlerIds),
                    'tenant_identifier' => $context->runtimeConfiguration?->tenantIdentifier,
                ],
            );

            return $this->paymentFailed(
                \sprintf('Completing a checkout needs exactly one payment instrument, %d given.', \count($handlerIds)),
                '$.payment.instruments',
            );
        }

        $advertisedHandlerIds = $this->advertisedHandlerIds($context);
        if (!\in_array($handlerIds[0], $advertisedHandlerIds, true)) {
            $this->logger?->warning(
                'A UCP checkout completion was refused: the payment handler the agent named is not advertised in the business profile.',
                [
                    'handler_id' => $handlerIds[0],
                    'advertised_handler_ids' => $advertisedHandlerIds,
                    'tenant_identifier' => $context->runtimeConfiguration?->tenantIdentifier,
                ],
            );

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

    private function paymentFailed(string $content, string $path): Message
    {
        return new Message('error', $content, 'recoverable', 'payment_failed', $path);
    }
}
