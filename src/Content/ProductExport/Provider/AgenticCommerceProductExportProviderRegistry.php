<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Provider;

readonly class AgenticCommerceProductExportProviderRegistry
{
    /**
     * @internal
     *
     * @param iterable<AbstractAgenticCommerceProductExportProvider> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    public function getByTechnicalName(string $technicalName): ?AbstractAgenticCommerceProductExportProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->getTechnicalName() === $technicalName) {
                return $provider;
            }
        }

        return null;
    }
}
