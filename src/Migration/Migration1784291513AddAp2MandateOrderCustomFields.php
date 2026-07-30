<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AgenticCommerce\Ucp\Ap2\Ap2MandateOrderPersister;

/**
 * Defines the order custom fields that store the verified AP2 checkout mandate of a
 * completed UCP checkout ({@see Ap2MandateOrderPersister}), so merchants can inspect
 * the mandate on the order detail page in the administration and retrieve it later as
 * dispute evidence.
 *
 * @internal
 */
final class Migration1784291513AddAp2MandateOrderCustomFields extends MigrationStep
{
    private const SET_NAME = 'swag_agentic_commerce_ap2';

    public function getCreationTimestamp(): int
    {
        return 1784291513;
    }

    public function update(Connection $connection): void
    {
        if (false !== $connection->fetchOne(
            'SELECT 1 FROM `custom_field_set` WHERE `name` = :name',
            ['name' => self::SET_NAME]
        )) {
            return;
        }

        $setId = Uuid::randomBytes();

        $connection->insert('custom_field_set', [
            'id' => $setId,
            'name' => self::SET_NAME,
            'config' => json_encode([
                'label' => [
                    'en-GB' => 'AP2 mandate',
                    'de-DE' => 'AP2-Mandat',
                ],
            ], \JSON_THROW_ON_ERROR),
            'active' => 1,
            'global' => 1,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);

        $connection->insert('custom_field_set_relation', [
            'id' => Uuid::randomBytes(),
            'set_id' => $setId,
            'entity_name' => 'order',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);

        $fields = [
            [
                'name' => Ap2MandateOrderPersister::CUSTOM_FIELD_MANDATE,
                'type' => 'text',
                'config' => [
                    'label' => [
                        'en-GB' => 'AP2 checkout mandate',
                        'de-DE' => 'AP2-Checkout-Mandat',
                    ],
                    'helpText' => [
                        'en-GB' => 'The raw SD-JWT credential that authorized this checkout. Keep as dispute evidence.',
                        'de-DE' => 'Das unveränderte SD-JWT-Credential, das diesen Checkout autorisiert hat. Als Streitfall-Nachweis aufbewahren.',
                    ],
                    'componentName' => 'sw-textarea-field',
                    'customFieldType' => 'textArea',
                    'customFieldPosition' => 1,
                ],
            ],
            [
                'name' => Ap2MandateOrderPersister::CUSTOM_FIELD_CLAIMS,
                'type' => 'text',
                'config' => [
                    'label' => [
                        'en-GB' => 'Verified mandate claims',
                        'de-DE' => 'Verifizierte Mandats-Claims',
                    ],
                    'componentName' => 'sw-textarea-field',
                    'customFieldType' => 'textArea',
                    'customFieldPosition' => 2,
                ],
            ],
            [
                'name' => Ap2MandateOrderPersister::CUSTOM_FIELD_VERIFIED_AT,
                'type' => 'datetime',
                'config' => [
                    'label' => [
                        'en-GB' => 'Mandate verified at',
                        'de-DE' => 'Mandat verifiziert am',
                    ],
                    'componentName' => 'sw-field',
                    'type' => 'date',
                    'dateType' => 'datetime',
                    'customFieldType' => 'date',
                    'customFieldPosition' => 3,
                ],
            ],
        ];

        foreach ($fields as $field) {
            $connection->insert('custom_field', [
                'id' => Uuid::randomBytes(),
                'name' => $field['name'],
                'type' => $field['type'],
                'config' => json_encode($field['config'], \JSON_THROW_ON_ERROR),
                'active' => 1,
                'set_id' => $setId,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            ]);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // Keep the custom fields: they hold AP2 dispute evidence on placed orders.
    }
}
