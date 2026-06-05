<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Fallback;

use Shopware\Core\Framework\Log\Package;

#[Package('discovery')]
final readonly class FallbackSalesChannelFile
{
    public function __construct(
        public string $fileFamily,
        public string $fileName,
        public string $templatePath,
        public string $contentType,
        public string $baseTemplateName,
    ) {
    }
}
