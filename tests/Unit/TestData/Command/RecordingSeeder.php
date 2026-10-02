<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\TestData\Command;

use Shopware\Core\Framework\Context;
use Swag\AgenticCommerce\TestData\PickedProducts;
use Swag\AgenticCommerce\TestData\Seeder\TestDataSeederInterface;

/**
 * @internal
 */
final class RecordingSeeder implements TestDataSeederInterface
{
    public ?PickedProducts $lastSelection = null;

    /**
     * @param \ArrayObject<int, string> $seederCalls  shared across seeders to observe the order
     * @param list<string>              $createdLines
     */
    public function __construct(
        private readonly string $name,
        private readonly \ArrayObject $seederCalls,
        private readonly ?string $unavailableReason = null,
        private readonly bool $isPresent = false,
        private readonly array $createdLines = [],
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function label(): string
    {
        return $this->name;
    }

    public function unavailableReason(): ?string
    {
        return $this->unavailableReason;
    }

    public function exists(Context $context): bool
    {
        return $this->isPresent;
    }

    public function create(array $salesChannelIds, PickedProducts $pickedProducts, Context $context): array
    {
        $this->seederCalls->append('create '.$this->name.' '.implode(',', $salesChannelIds));
        $this->lastSelection = $pickedProducts;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->createdLines;
    }

    public function remove(Context $context): bool
    {
        $this->seederCalls->append('remove '.$this->name);

        return $this->isPresent;
    }
}
