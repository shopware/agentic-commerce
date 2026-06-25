<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * Adds the Agentic Commerce sales channel type directly to the core
 * `sales_channel_type` / `sales_channel_type_translation` tables.
 *
 * The UUID is shared with {@see Defaults::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE} in
 * Shopware 6.7.10+, so merchants can uninstall this plugin later without losing
 * their Agentic Commerce sales channels.
 *
 * If the row already exists — because the core migration ran before this plugin
 * migration did — the method returns immediately. Otherwise, the row is inserted
 * and {@see self::CORE_MIGRATION_CLASS} is shadowed in the `migration` table so
 * `system:update:finish` after an upgrade to 6.7.10+ does not attempt to re-run it.
 *
 * The shadow can be removed once 6.5.x and 6.6.x are no longer supported lanes,
 * because all installations will have passed through a Shopware version that natively
 * records {@see self::CORE_MIGRATION_CLASS} in the migration table.
 *
 * @internal
 */
class Migration1773329152AddAgenticCommerceSalesChannelType extends MigrationStep
{
    /**
     * Class name of the equivalent Shopware core migration (added in 6.7.10).
     * Stored as a constant so it appears in exactly one place across production
     * code and tests, and so any future rename is caught immediately.
     */
    public const CORE_MIGRATION_CLASS = 'Shopware\Core\Migration\V6_7\Migration1773329152AddAgenticAiSalesChannelType';

    public function getCreationTimestamp(): int
    {
        return 1773329152;
    }

    public function update(Connection $connection): void
    {
        $salesChannelTypeId = Uuid::fromHexToBytes(SwagAgenticCommerce::SALES_CHANNEL_TYPE_AGENTIC_COMMERCE);

        if (false !== $connection->fetchOne(
            'SELECT 1 FROM `sales_channel_type` WHERE `id` = :id',
            ['id' => $salesChannelTypeId]
        )) {
            return;
        }

        $systemLanguageId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        $localizedLanguageIds = $this->fetchLocalizedLanguageIds($connection);

        $connection->transactional(static function (Connection $connection) use ($salesChannelTypeId, $systemLanguageId, $localizedLanguageIds): void {
            $connection->insert('sales_channel_type', [
                'id' => $salesChannelTypeId,
                'icon_name' => 'regular-sparkle',
                'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);

            $translations = [
                $systemLanguageId => [
                    'name' => 'Agentic Commerce',
                    'manufacturer' => 'shopware AG',
                    'description' => 'Sales channel for agentic commerce platforms',
                ],
            ];

            $englishLanguageId = $localizedLanguageIds['en-GB'] ?? null;
            if (null !== $englishLanguageId && $englishLanguageId !== $systemLanguageId) {
                $translations[$englishLanguageId] = [
                    'name' => 'Agentic Commerce',
                    'manufacturer' => 'shopware AG',
                    'description' => 'Sales channel for agentic commerce platforms',
                ];
            }

            $germanLanguageId = $localizedLanguageIds['de-DE'] ?? null;
            if (null !== $germanLanguageId && $germanLanguageId !== $systemLanguageId) {
                $translations[$germanLanguageId] = [
                    'name' => 'Agentic Commerce',
                    'manufacturer' => 'shopware AG',
                    'description' => 'Verkaufskanal für Agentic-Commerce-Plattformen',
                ];
            }

            foreach ($translations as $languageId => $translation) {
                $connection->insert('sales_channel_type_translation', [
                    'sales_channel_type_id' => $salesChannelTypeId,
                    'language_id' => $languageId,
                    'name' => $translation['name'],
                    'manufacturer' => $translation['manufacturer'],
                    'description' => $translation['description'],
                    'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]);
            }
        });

        $this->shadowCoreMigration($connection);
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    /**
     * Pre-mark {@see self::CORE_MIGRATION_CLASS} as executed so `system:update:finish`
     * after an upgrade to 6.7.10+ treats it as done and does not re-INSERT the row
     * this migration already wrote. INSERT IGNORE is a no-op if the core migration
     * has already been recorded (e.g. on a fresh 6.7.10 install).
     */
    private function shadowCoreMigration(Connection $connection): void
    {
        $connection->executeStatement(
            'INSERT IGNORE INTO `migration` (`class`, `creation_timestamp`, `update`, `update_destructive`)
             VALUES (:class, :ts, NOW(), NULL)',
            ['class' => self::CORE_MIGRATION_CLASS, 'ts' => 1773329152]
        );
    }

    /**
     * @return array<string, string>
     */
    private function fetchLocalizedLanguageIds(Connection $connection): array
    {
        return $connection->fetchAllKeyValue(<<<'SQL'
                SELECT locale.code, language.id
                FROM language
                INNER JOIN locale ON language.locale_id = locale.id
                WHERE locale.code IN ('de-DE', 'en-GB')
            SQL);
    }
}
