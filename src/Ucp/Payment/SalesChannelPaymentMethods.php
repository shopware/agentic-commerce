<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Payment;

use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/** @internal */
#[Package('checkout')]
final class SalesChannelPaymentMethods
{
    /**
     * @param EntityRepository<PaymentMethodCollection> $paymentMethodRepository
     */
    public function __construct(
        private readonly EntityRepository $paymentMethodRepository,
        private readonly AbstractPaymentMethodRoute $paymentMethodRoute,
    ) {
    }

    /**
     * @param string $coreHandlerClass the `handlerIdentifier` of the payment method
     *
     * @return string|null the first active payment method of the sales channel that runs the handler
     */
    public function idForHandler(string $coreHandlerClass, string $salesChannelId): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('active', true),
            new EqualsFilter('salesChannels.id', $salesChannelId),
            new EqualsFilter('handlerIdentifier', $coreHandlerClass),
        );
        $criteria->addSorting(new FieldSorting('position'));
        $criteria->setLimit(1);

        $paymentMethodId = $this->paymentMethodRepository->searchIds($criteria, Context::createDefaultContext())->firstId();

        return \is_string($paymentMethodId) ? $paymentMethodId : null;
    }

    /**
     * @param list<string> $coreHandlerClasses
     *
     * @return array<string, string>
     */
    public function idsByHandlerClass(array $coreHandlerClasses, string $salesChannelId): array
    {
        if ([] === $coreHandlerClasses) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('active', true),
            new EqualsFilter('salesChannels.id', $salesChannelId),
            new EqualsAnyFilter('handlerIdentifier', $coreHandlerClasses),
        );
        $criteria->addSorting(new FieldSorting('position'));

        $paymentMethodIdByHandlerClass = [];
        // Read through Entity, because Shopware 6.5 does not type the search result as a PaymentMethodCollection.
        foreach ($this->paymentMethodRepository->search($criteria, Context::createDefaultContext())->getEntities() as $paymentMethod) {
            $handlerClass = $paymentMethod->get('handlerIdentifier');
            if (\is_string($handlerClass)) {
                $paymentMethodIdByHandlerClass[$handlerClass] ??= $paymentMethod->getUniqueIdentifier();
            }
        }

        return $paymentMethodIdByHandlerClass;
    }

    /**
     * Whether the buyer of the context may pay with the method, including its availability rule.
     */
    public function isAvailable(string $paymentMethodId, SalesChannelContext $context): bool
    {
        $availablePaymentMethods = $this->paymentMethodRoute->load(
            new Request([], ['onlyAvailable' => true]),
            $context,
            new Criteria([$paymentMethodId]),
        )->getPaymentMethods();

        return $availablePaymentMethods->has($paymentMethodId);
    }
}
