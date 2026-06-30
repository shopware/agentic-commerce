<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Validator;

use Shopware\Core\Content\ProductExport\Error\ErrorCollection;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Content\ProductExport\Validator\ValidatorInterface;
use Swag\AgenticCommerce\Content\ProductExport\AgenticProductExportException;

/**
 * Base class for provider-specific product export validators.
 *
 * Reads the configured provider directly from the {@see ProductExportEntity}
 * (populated via the plugin's DAL extension and hydrator) and only delegates
 * to {@see validateProviderExport()} when it matches the concrete
 * implementation's technical name.
 *
 * @internal
 */
abstract class AbstractProviderValidator implements ValidatorInterface
{
    public function __construct(
        private readonly JsonlRowParser $jsonlRowParser,
    ) {
    }

    final public function validate(ProductExportEntity $productExportEntity, string $productExportContent, ErrorCollection $errors): void
    {
        if (!$productExportEntity->has('provider')) {
            return;
        }

        if ($productExportEntity->get('provider') !== $this->getProviderTechnicalName()) {
            return;
        }

        $this->validateProviderExport($productExportEntity, $productExportContent, $errors);
    }

    abstract protected function getProviderTechnicalName(): string;

    abstract protected function validateProviderExport(ProductExportEntity $productExportEntity, string $productExportContent, ErrorCollection $errors): void;

    /**
     * @return list<array{line:int, row:array<string, mixed>}>
     *
     * @throws AgenticProductExportException
     */
    protected function parseJsonlRows(string $productExportContent): array
    {
        return $this->jsonlRowParser->parse($productExportContent);
    }
}
