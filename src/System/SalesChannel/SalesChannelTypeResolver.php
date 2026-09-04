<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\System\SalesChannel;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Results are cached on the instance, not in a shared cache: a channel's
 * `type_id` cannot change, so a shared cache would only add an invalidator.
 *
 * @internal
 */
#[Package('discovery')]
final class SalesChannelTypeResolver extends AbstractSalesChannelTypeResolver implements ResetInterface
{
    /**
     * @var array<string, SalesChannelTypeClassification>
     */
    private array $resolved = [];

    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
    ) {
    }

    public function getDecorated(): AbstractSalesChannelTypeResolver
    {
        throw new DecorationPatternException(self::class);
    }

    public function reset(): void
    {
        $this->resolved = [];
    }

    public function resolve(string $salesChannelId): SalesChannelTypeClassification
    {
        return $this->resolveMany([$salesChannelId])[$salesChannelId] ?? SalesChannelTypeClassification::Other;
    }

    public function resolveMany(array $salesChannelIds): array
    {
        $unresolved = array_values(array_unique(array_filter(
            $salesChannelIds,
            fn (string $salesChannelId): bool => !isset($this->resolved[$salesChannelId]),
        )));

        if ([] !== $unresolved) {
            $this->read($unresolved);
        }

        $classes = [];
        foreach (array_unique($salesChannelIds) as $salesChannelId) {
            $classes[$salesChannelId] = $this->resolved[$salesChannelId] ?? SalesChannelTypeClassification::Other;
        }

        return $classes;
    }

    /**
     * @param list<string> $salesChannelIds
     */
    private function read(array $salesChannelIds): void
    {
        foreach ($salesChannelIds as $salesChannelId) {
            $this->resolved[$salesChannelId] = SalesChannelTypeClassification::Other;
        }

        $salesChannels = $this->salesChannelRepository
            ->search(new Criteria($salesChannelIds), Context::createDefaultContext())
            ->getEntities();

        foreach ($salesChannels as $salesChannel) {
            if (!$salesChannel instanceof SalesChannelEntity) {
                continue;
            }

            $this->resolved[$salesChannel->getId()] = SalesChannelTypeClassification::forTypeId($salesChannel->getTypeId());
        }
    }
}
