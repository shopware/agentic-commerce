<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\TestDataEnvironment;

/**
 * Assigns PayPal's active payment methods to the target sales channels. Only the assignments this seeder
 * added are recorded and removed again, so a merchant's own PayPal setup survives `--remove`.
 *
 * @internal
 */
#[Package('framework')]
final class PayPalSeeder implements TestDataSeederInterface
{
    public const ASSIGNMENTS_CONFIG_KEY = 'SwagAgenticCommerce.testData.paypalAssignments';

    private const HANDLER_PREFIX = 'Swag\\PayPal\\';
    private const CLIENT_ID_KEYS = ['SwagPayPal.settings.clientId', 'SwagPayPal.settings.clientIdSandbox'];

    /**
     * @param EntityRepository<PaymentMethodCollection>  $paymentMethodRepository
     * @param EntityRepository<SalesChannelCollection>   $salesChannelRepository
     * @param EntityRepository<EntityCollection<Entity>> $salesChannelPaymentMethodRepository
     */
    public function __construct(
        private readonly EntityRepository $paymentMethodRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $salesChannelPaymentMethodRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly TestDataEnvironment $environment,
    ) {
    }

    public function label(): string
    {
        return 'PayPal payment methods in the target sales channels';
    }

    public function unavailableReason(): ?string
    {
        return $this->environment->payPalUnavailableReason();
    }

    public function exists(Context $context): bool
    {
        return [] !== $this->recordedPaymentMethodIdsBySalesChannelId();
    }

    public function create(array $salesChannelIds, PickedProducts $pickedProducts, Context $context): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('active', true))
            ->addFilter(new PrefixFilter('handlerIdentifier', self::HANDLER_PREFIX));
        $paymentMethods = $this->paymentMethodRepository->search($criteria, $context)->getEntities();
        if (0 === $paymentMethods->count()) {
            return ['SwagPayPal has no active payment method, nothing assigned.'];
        }

        $salesChannels = $this->salesChannelRepository->search(
            (new Criteria($salesChannelIds))->addAssociation('paymentMethods'),
            $context,
        )->getEntities();

        $paymentMethodIdsBySalesChannelId = [];
        $reportLines = [];
        foreach ($salesChannels as $salesChannel) {
            $assignedPaymentMethodIds = $salesChannel->getPaymentMethods()?->getIds() ?? [];
            $unassignedPaymentMethods = $paymentMethods->filter(static fn (PaymentMethodEntity $method): bool => !\in_array($method->getId(), $assignedPaymentMethodIds, true));
            $channelName = (string) ($salesChannel->getTranslation('name') ?? $salesChannel->getName() ?? $salesChannel->getId());

            if (0 === $unassignedPaymentMethods->count()) {
                $reportLines[] = $channelName.': all PayPal methods were already assigned';
                continue;
            }

            $this->salesChannelRepository->update([[
                'id' => $salesChannel->getId(),
                'paymentMethods' => array_map(static fn (string $id): array => ['id' => $id], array_values($unassignedPaymentMethods->getIds())),
            ]], $context);

            $paymentMethodIdsBySalesChannelId[$salesChannel->getId()] = array_values($unassignedPaymentMethods->getIds());
            $reportLines[] = $channelName.': added '.implode(', ', $unassignedPaymentMethods->map(
                static fn (PaymentMethodEntity $method): string => (string) ($method->getTranslation('name') ?? $method->getName()),
            ));

            if (!$this->hasCredentials($salesChannel->getId())) {
                $reportLines[] = $channelName.': PayPal has no API credentials, so checkout hides its methods';
            }
        }

        if ([] !== $paymentMethodIdsBySalesChannelId) {
            $this->systemConfigService->set(self::ASSIGNMENTS_CONFIG_KEY, $paymentMethodIdsBySalesChannelId);
        }

        return $reportLines;
    }

    public function remove(Context $context): bool
    {
        $paymentMethodIdsBySalesChannelId = $this->recordedPaymentMethodIdsBySalesChannelId();
        if ([] === $paymentMethodIdsBySalesChannelId) {
            return false;
        }

        $salesChannels = $this->salesChannelRepository->search(new Criteria(array_keys($paymentMethodIdsBySalesChannelId)), $context)->getEntities();

        $deletions = [];
        foreach ($paymentMethodIdsBySalesChannelId as $salesChannelId => $paymentMethodIds) {
            // The default payment method must stay assigned; the merchant may have picked a PayPal one since.
            $defaultPaymentMethodId = $salesChannels->get($salesChannelId)?->getPaymentMethodId();
            foreach ($paymentMethodIds as $paymentMethodId) {
                if ($paymentMethodId !== $defaultPaymentMethodId) {
                    $deletions[] = ['salesChannelId' => $salesChannelId, 'paymentMethodId' => $paymentMethodId];
                }
            }
        }

        if ([] !== $deletions) {
            $this->salesChannelPaymentMethodRepository->delete($deletions, $context);
        }

        $this->systemConfigService->set(self::ASSIGNMENTS_CONFIG_KEY, null);

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    private function recordedPaymentMethodIdsBySalesChannelId(): array
    {
        $storedAssignments = $this->systemConfigService->get(self::ASSIGNMENTS_CONFIG_KEY);
        if (!\is_array($storedAssignments)) {
            return [];
        }

        $recordedPaymentMethodIdsBySalesChannelId = [];
        foreach ($storedAssignments as $salesChannelId => $paymentMethodIds) {
            if (\is_string($salesChannelId) && \is_array($paymentMethodIds)) {
                $recordedPaymentMethodIdsBySalesChannelId[$salesChannelId] = array_values(array_filter($paymentMethodIds, 'is_string'));
            }
        }

        return $recordedPaymentMethodIdsBySalesChannelId;
    }

    private function hasCredentials(string $salesChannelId): bool
    {
        foreach (self::CLIENT_ID_KEYS as $key) {
            if ('' !== $this->systemConfigService->getString($key, $salesChannelId)) {
                return true;
            }
        }

        return false;
    }
}
