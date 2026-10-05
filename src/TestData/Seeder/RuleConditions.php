<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Seeder;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Rule\Container\AndRule;
use Shopware\Core\Framework\Rule\Container\OrRule;
use Swag\AgenticCommerce\TestData\TestDataIds;

/**
 * @internal
 */
#[Package('framework')]
final class RuleConditions
{
    /**
     * Wraps one condition in the OR/AND containers the Administration rule builder expects as the root.
     *
     * @param array<string, mixed> $value
     *
     * @return list<array<string, mixed>>
     */
    public static function single(string $ruleKey, string $type, array $value): array
    {
        return [[
            'id' => TestDataIds::id($ruleKey.'.condition.or'),
            'type' => OrRule::RULE_NAME,
            'position' => 0,
            'children' => [[
                'id' => TestDataIds::id($ruleKey.'.condition.and'),
                'type' => AndRule::RULE_NAME,
                'position' => 0,
                'children' => [[
                    'id' => TestDataIds::id($ruleKey.'.condition'),
                    'type' => $type,
                    'position' => 0,
                    'value' => $value,
                ]],
            ]],
        ]];
    }
}
