<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Service;

use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Content\ProductExport\Service\ProductExportRendererInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AgenticCommerce\SwagAgenticCommerce;

/**
 * Decorates the core ProductExportRenderer so that bodies rendered for an
 * agentic commerce export in JSONL format are always a single JSON object per
 * line.
 *
 * The core renderer appends a newline after every body render, which would
 * produce blank lines for products whose template renders to empty output, and
 * would preserve template whitespace noise inside a JSONL row. This decorator
 * trims the rendered body, skips empty renders entirely, and re-encodes each
 * line so that every non-empty product becomes exactly one JSONL row.
 *
 * @internal
 */
class JsonlAwareProductExportRenderer implements ProductExportRendererInterface
{
    public function __construct(
        private readonly ProductExportRendererInterface $inner,
    ) {
    }

    public function renderHeader(
        ProductExportEntity $productExport,
        SalesChannelContext $salesChannelContext,
    ): string {
        return $this->inner->renderHeader($productExport, $salesChannelContext);
    }

    public function renderFooter(
        ProductExportEntity $productExport,
        SalesChannelContext $salesChannelContext,
    ): string {
        return $this->inner->renderFooter($productExport, $salesChannelContext);
    }

    /** @param array<mixed> $data */
    public function renderBody(
        ProductExportEntity $productExport,
        SalesChannelContext $salesChannelContext,
        array $data,
    ): string {
        $rendered = $this->inner->renderBody($productExport, $salesChannelContext, $data);

        if (SwagAgenticCommerce::FILE_FORMAT_JSONL !== $productExport->getFileFormat()) {
            return $rendered;
        }

        $trimmed = trim($rendered);

        if ('' === $trimmed) {
            return '';
        }

        try {
            $decoded = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);

            if (\is_array($decoded)) {
                // URLs from media filenames may contain unescaped spaces; encode them so
                // the row passes downstream RFC 3986 validation (FILTER_VALIDATE_URL).
                array_walk_recursive($decoded, static function (mixed &$value): void {
                    if (\is_string($value) && 1 === preg_match('#^https?://#i', $value)) {
                        $value = str_replace(' ', '%20', $value);
                    }
                });

                return json_encode($decoded, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES).\PHP_EOL;
            }
        } catch (\JsonException) {
            // The validator will surface a JsonlValidationError for this row.
        }

        return $trimmed.\PHP_EOL;
    }
}
