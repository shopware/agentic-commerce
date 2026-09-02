<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\System\SalesChannel\Fixtures;

use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Swag\AgenticCommerce\System\SalesChannel\AbstractSalesChannelTypeResolver;
use Swag\AgenticCommerce\System\SalesChannel\SalesChannelTypeClass;

/** @internal */
final class StaticSalesChannelTypeResolver extends AbstractSalesChannelTypeResolver
{
    /**
     * @param array<string, SalesChannelTypeClass> $classes
     */
    public function __construct(
        private readonly SalesChannelTypeClass $default = SalesChannelTypeClass::Other,
        private readonly array $classes = [],
    ) {
    }

    public function getDecorated(): AbstractSalesChannelTypeResolver
    {
        throw new DecorationPatternException(self::class);
    }

    public function resolve(string $salesChannelId): SalesChannelTypeClass
    {
        return $this->classes[$salesChannelId] ?? $this->default;
    }

    public function resolveMany(array $salesChannelIds): array
    {
        $classes = [];
        foreach ($salesChannelIds as $salesChannelId) {
            $classes[$salesChannelId] = $this->resolve($salesChannelId);
        }

        return $classes;
    }
}
