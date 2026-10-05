<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Catalogue;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\TestData\TranslatedText;

/**
 * @internal
 */
#[Package('framework')]
final class CatalogueImage
{
    public const ROLE_COVER = 'cover';
    public const ROLE_VARIANT = 'variant';

    public function __construct(
        public readonly string $file,
        public readonly string $role,
        public readonly TranslatedText $alt,
    ) {
    }

    public static function fromJsonObject(CatalogueJsonObject $imageJson): self
    {
        return new self(CatalogueArchive::validImagePath($imageJson->requireString('file')), $imageJson->requireString('role'), $imageJson->requireTranslatedText('alt'));
    }

    /**
     * The file name without extension, unique per product: cover, lifestyle, scale, variant-<key>.
     */
    public function shotName(): string
    {
        return basename($this->file, '.webp');
    }
}
