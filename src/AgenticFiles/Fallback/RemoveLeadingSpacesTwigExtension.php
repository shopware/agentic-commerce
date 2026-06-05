<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Fallback;

use Shopware\Core\Framework\Log\Package;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

#[Package('discovery')]
final class RemoveLeadingSpacesTwigExtension extends AbstractExtension
{
    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('remove_leading_spaces', $this->removeLeadingSpaces(...)),
        ];
    }

    public function removeLeadingSpaces(mixed $content): string
    {
        $content = (string) $content;

        return preg_replace('/^[ \t]+/m', '', $content) ?? $content;
    }
}
