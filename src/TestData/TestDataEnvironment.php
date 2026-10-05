<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\Log\Package;

/**
 * Decides which plugin-dependent parts of the test data set this shop can hold. A plugin counts only when
 * it is active: Composer-managed plugins stay autoloadable while inactive, so `class_exists()` would lie.
 *
 * @internal
 */
#[Package('framework')]
final class TestDataEnvironment
{
    public const PAYPAL_PLUGIN = 'Swag\\PayPal\\SwagPayPal';
    public const COMMERCIAL_PLUGIN = 'Shopware\\Commercial\\SwagCommercial';
    public const DYNAMIC_ACCESS_PLUGIN = 'Swag\\DynamicAccess\\SwagDynamicAccess';
    public const DYNAMIC_ACCESS_PRODUCT_FIELD = 'swagDynamicAccessRules';
    public const BUNDLE_ITEM_ENTITY = 'bundle_item';
    public const BUNDLE_PRODUCT_TYPE = 'grouped_bundle';

    /**
     * @param array<string, mixed> $activePlugins       `kernel.active_plugins`, keyed by plugin base class
     * @param ?object              $productTypeRegistry core's `ProductTypeRegistry`, which Shopware ships from 6.7.7; null before
     */
    public function __construct(
        private readonly array $activePlugins,
        private readonly DefinitionInstanceRegistry $definitionRegistry,
        private readonly ?object $productTypeRegistry,
    ) {
    }

    public function payPalUnavailableReason(): ?string
    {
        if (!$this->isActive(self::PAYPAL_PLUGIN)) {
            return 'SwagPayPal is not installed or not active.';
        }

        return null;
    }

    public function dynamicAccessUnavailableReason(): ?string
    {
        if (!$this->isActive(self::DYNAMIC_ACCESS_PLUGIN)) {
            return 'SwagDynamicAccess is not installed or not active.';
        }

        if (!$this->definitionRegistry->getByEntityName(ProductDefinition::ENTITY_NAME)->getFields()->has(self::DYNAMIC_ACCESS_PRODUCT_FIELD)) {
            return 'This SwagDynamicAccess version does not restrict products by rule.';
        }

        return null;
    }

    public function bundleUnavailableReason(): ?string
    {
        if (!$this->isActive(self::COMMERCIAL_PLUGIN)) {
            return 'SwagCommercial is not installed or not active.';
        }

        if (null === $this->productTypeRegistry || !method_exists($this->productTypeRegistry, 'hasType') || !$this->definitionRegistry->has(self::BUNDLE_ITEM_ENTITY)) {
            return 'Product bundles need SwagCommercial with product bundles on Shopware 6.7.14 or newer.';
        }

        // Commercial registers the bundle product type only when its licence carries the bundle service toggle.
        if (!$this->productTypeRegistry->hasType(self::BUNDLE_PRODUCT_TYPE)) {
            return 'The Shopware Commercial licence does not include product bundles (PRODUCT_BUNDLE).';
        }

        return null;
    }

    private function isActive(string $pluginClass): bool
    {
        return \array_key_exists($pluginClass, $this->activePlugins);
    }
}
