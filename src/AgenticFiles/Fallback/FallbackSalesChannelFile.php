<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Fallback;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('discovery')]
final class FallbackSalesChannelFile
{
    public function __construct(
        public readonly string $fileFamily,
        public readonly string $fileName,
        public readonly string $templatePath,
        public readonly string $contentType,
        public readonly string $baseTemplateName,
    ) {
    }
}
