<?php

declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer;

/**
 * Cross-version stub: 6.7.x narrowed getPrimaryKey() to string-only in PHPDoc,
 * but the method still accepts array primary keys at runtime on composite PKs in older lanes.
 * Declaring array|string here keeps the is_array() guard in tracking code valid on all lanes.
 */
class EntityWriteResult
{
    /**
     * @return array<string, string>|string
     */
    public function getPrimaryKey(): array|string
    {
    }
}
