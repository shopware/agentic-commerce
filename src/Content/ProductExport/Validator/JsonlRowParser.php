<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport\Validator;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Content\ProductExport\AgenticProductExportException;

/**
 * @internal
 */
#[Package('discovery')]
class JsonlRowParser
{
    /**
     * @return list<array{line:int, row:array<string, mixed>}>
     *
     * @throws AgenticProductExportException
     */
    public function parse(string $content): array
    {
        $lines = preg_split('/\R/', $content);
        \assert(false !== $lines);

        $decodedRows = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            try {
                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw AgenticProductExportException::malformedJsonlLine($exception->getMessage(), $lineNumber + 1);
            }

            if (!\is_array($decoded) || array_is_list($decoded)) {
                throw AgenticProductExportException::jsonlLineMustDecodeToObject($lineNumber + 1);
            }

            $decodedRows[] = [
                'line' => $lineNumber + 1,
                'row' => $decoded,
            ];
        }

        return $decodedRows;
    }
}
