<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Quote;

/**
 * Feature detection for the commercial B2B quote backend.
 *
 * Quote entities and the Store API quote routes live in SwagCommercial (Evolve
 * tier). This plugin must install and run without it, so the dependency is
 * detected at runtime and never declared in composer: class existence decides
 * whether the gateway is registered at all, the license toggle decides whether
 * it serves requests.
 *
 * @internal
 */
final class QuoteBackendFeature
{
    /** License toggle guarding every commercial quote route. */
    public const LICENSE_TOGGLE = 'QUOTE_MANAGEMENT-8702512';

    /** Feature key in `customer_specific_features.features` (a map, not a list). */
    public const CUSTOMER_FEATURE = 'QUOTE_MANAGEMENT';

    /**
     * Class-name literals, not `::class`: these classes need not be loadable.
     */
    private const QUOTE_MANAGEMENT_CLASS = 'Shopware\\Commercial\\B2B\\QuoteManagement\\QuoteManagement';
    private const LICENSE_CLASS = 'Shopware\\Commercial\\Licensing\\License';

    public static function isAvailableByClass(): bool
    {
        return class_exists(self::QUOTE_MANAGEMENT_CLASS) && class_exists(self::LICENSE_CLASS);
    }

    public static function isLicensed(): bool
    {
        if (!self::isAvailableByClass()) {
            return false;
        }

        try {
            /** @var callable(string): (string|bool|int) $check */
            $check = [self::LICENSE_CLASS, 'get'];

            return false !== $check(self::LICENSE_TOGGLE);
        } catch (\Throwable) {
            return false;
        }
    }
}
