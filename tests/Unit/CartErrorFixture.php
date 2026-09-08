<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * A cart error at a chosen level.
 *
 * Shopware's own errors each fix their level, so covering all three would mean picking
 * three unrelated core classes and inheriting whatever else they carry — and one of them
 * would have to be a level whose only core examples sit behind version-specific
 * namespaces this plugin supports across 6.5 to trunk.
 *
 * @internal
 */
final class CartErrorFixture extends Error
{
    public function __construct(
        private readonly int $level,
        string $message,
        private readonly string $messageKey,
    ) {
        parent::__construct($message);
    }

    public function getId(): string
    {
        return $this->messageKey;
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function blockOrder(): bool
    {
        return false;
    }

    public function getParameters(): array
    {
        return [];
    }
}
