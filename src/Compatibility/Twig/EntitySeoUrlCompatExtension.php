<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Compatibility\Twig;

use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlRouteRegistry;
use Shopware\Core\Framework\Adapter\Twig\Extension\SeoUrlFunctionExtension;
use Shopware\Core\Framework\Log\Package;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @internal
 *
 * @deprecated Backport of core's `entitySeoUrl` Twig function for Shopware < 6.7.14. From 6.7.14 core
 * registers `entitySeoUrl` itself and {@see \Swag\AgenticCommerce\DependencyInjection\AgenticCommerceCoexistenceCompilerPass}
 * removes this service, so the backport is only ever active on older versions. Drop this class once the
 * plugin's minimum supported Shopware version is >= 6.7.14.
 *
 * Unlike core's `entitySeoUrl(name, primaryKey)` this backport requires the route primary-key parameter
 * as a third argument, because `SeoUrlRouteConfig::getPrimaryKeyParameter()` (used by core to derive it)
 * only exists from 6.7.14. The shared templates therefore always pass it, e.g.
 * `entitySeoUrl('product', product.id, 'productId')`; core simply ignores the extra argument.
 */
#[Package('discovery')]
final class EntitySeoUrlCompatExtension extends AbstractExtension
{
    public function __construct(
        private readonly SeoUrlRouteRegistry $seoUrlRouteRegistry,
        private readonly SeoUrlFunctionExtension $seoUrlFunctionExtension,
    ) {
    }

    public function getFunctions(): array
    {
        // This service is only wired on Shopware < 6.7.14 (the compiler pass removes it once core
        // ships `entitySeoUrl`), so the function can be registered unconditionally here.
        return [
            new TwigFunction('entitySeoUrl', $this->entitySeoUrl(...)),
        ];
    }

    public function entitySeoUrl(string $entityName, string $primaryKey, string $parameterName): string
    {
        $route = $this->seoUrlRouteRegistry->findByDefinition($entityName)[0] ?? null;

        if (null === $route) {
            return '';
        }

        return $this->seoUrlFunctionExtension->seoUrl(
            $route->getConfig()->getRouteName(),
            [$parameterName => $primaryKey],
        );
    }
}
