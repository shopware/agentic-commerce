<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Subscriber;

use Shopware\Core\Content\ProductExport\Event\ProductExportProductCriteriaEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Loads the associations that essential-characteristics resolution needs onto the
 * exported product stream. Resolution happens inside a PHP-backed Twig function, so
 * core's template-variable parser cannot infer these associations from the body
 * template; they must be added explicitly here.
 *
 * Scoped to the agentic product-export providers via the plugin's `provider` field
 * so other product-comparison feeds are unaffected.
 *
 * @internal
 */
class AgenticCommerceProductExportCriteriaSubscriber implements EventSubscriberInterface
{
    private const AGENTIC_PROVIDERS = ['open-ai', 'google'];

    public static function getSubscribedEvents(): array
    {
        return [
            ProductExportProductCriteriaEvent::class => 'addEssentialCharacteristicsAssociations',
        ];
    }

    public function addEssentialCharacteristicsAssociations(ProductExportProductCriteriaEvent $event): void
    {
        $productExport = $event->getProductExport();
        $provider = $productExport->has('provider') ? $productExport->get('provider') : null;

        if (!\is_string($provider) || !\in_array($provider, self::AGENTIC_PROVIDERS, true)) {
            return;
        }

        $criteria = $event->getCriteria();

        // Essential characteristics (core ProductFeatureSet) plus the data their
        // resolution reads: the feature-set definition and property groups/options.
        // Custom-field values live on the product `customFields` JSON field and the
        // reference price carries its own unit name, so neither needs an association.
        $criteria->addAssociation('featureSet');
        $criteria->addAssociation('properties.group');

        // Unit-pricing measures (measurements export) need the product's unit entity.
        $criteria->addAssociation('unit');
    }
}
