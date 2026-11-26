<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout\Payment;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannel\AbstractContextSwitchRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\RequestContext;

/**
 * The default: switches the context to the payment method that the registered handler of the instrument
 * resolves. An empty or unavailable method is refused, never replaced by the sales channel default.
 *
 * Replace it by aliasing {@see AbstractCompletionPaymentApplier} to your own service, or decorate this one
 * and delegate through `getDecorated()`. This is the end of the chain, so its own `getDecorated()` throws.
 *
 * @internal
 */
#[Package('framework')]
final class RegisteredHandlerCompletionPayment extends AbstractCompletionPaymentApplier
{
    public function __construct(
        private readonly RegisteredHandlerPaymentMethodResolver $paymentMethodResolver,
        private readonly AbstractContextSwitchRoute $contextSwitchRoute,
        private readonly SalesChannelContextServiceInterface $contextService,
    ) {
    }

    public function getDecorated(): AbstractCompletionPaymentApplier
    {
        throw new DecorationPatternException(self::class);
    }

    public function apply(
        ?PaymentInstrument $instrument,
        SalesChannelContext $context,
        RequestContext $requestContext,
    ): SalesChannelContext {
        $paymentMethodId = $this->paymentMethodResolver->resolve($instrument, $context, $requestContext);

        $this->contextSwitchRoute->switchContext(
            new RequestDataBag([SalesChannelContextService::PAYMENT_METHOD_ID => $paymentMethodId]),
            $context,
        );

        return $this->contextService->get(new SalesChannelContextServiceParameters(
            $context->getSalesChannelId(),
            $context->getToken(),
            $context->getLanguageId(),
            $context->getCurrencyId(),
            $context->getDomainId(),
            null,
            $context->getCustomerId(),
        ));
    }
}
