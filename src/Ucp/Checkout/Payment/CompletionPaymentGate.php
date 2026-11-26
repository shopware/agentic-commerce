<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Ucp\Sdk\Model\Common\Message;
use Ucp\Sdk\Model\RequestContext;

/**
 * Refuses an instrument naming a handler that the business profile does not advertise for the sales channel.
 *
 * The SDK has already refused a completion without exactly one instrument.
 *
 * @internal
 */
#[Package('checkout')]
final class CompletionPaymentGate
{
    public function __construct(
        private readonly AdvertisedPaymentHandlers $advertisedHandlers,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param string|null $handlerId the `handler_id` of the instrument, null when the entry point carries none
     *
     * @return Message|null the refusal, or null when the instrument may go on
     *
     * @throws UcpConfigException when the request carries no runtime configuration
     */
    public function refusal(?string $handlerId, RequestContext $context): ?Message
    {
        if (null === $handlerId) {
            return PaymentFailedMessage::recoverable(
                'Completing a checkout needs a payment instrument, and this entry point carries none.',
                '$.payment.instruments',
            );
        }

        $advertisedHandlerIds = $this->advertisedHandlers->ids($context);
        if (!\in_array($handlerId, $advertisedHandlerIds, true)) {
            $this->logger?->warning(
                'A UCP checkout completion was refused: the payment handler the agent named is not advertised in the business profile.',
                [
                    'handler_id' => $handlerId,
                    'advertised_handler_ids' => $advertisedHandlerIds,
                    'tenant_identifier' => $context->runtimeConfiguration?->tenantIdentifier,
                ],
            );

            return PaymentFailedMessage::recoverable(
                \sprintf('The payment handler "%s" is not one this business advertises.', $handlerId),
                '$.payment.instruments[0].handler_id',
            );
        }

        return null;
    }
}
