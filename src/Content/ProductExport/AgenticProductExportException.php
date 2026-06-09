<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Content\ProductExport;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exception factories for agentic commerce product exports.
 *
 * Mirrors the JSONL-related error constants that Shopware 6.7.10+ adds to
 * {@see \Shopware\Core\Content\ProductExport\ProductExportException}.
 */
class AgenticProductExportException extends HttpException
{
    public const JSONL_MALFORMED_LINE_EXCEPTION = 'PRODUCT_EXPORT__JSONL_MALFORMED_LINE_EXCEPTION';
    public const JSONL_LINE_NOT_OBJECT_EXCEPTION = 'PRODUCT_EXPORT__JSONL_LINE_NOT_OBJECT_EXCEPTION';
    public const RENDER_PRODUCT_EXCEPTION = 'PRODUCT_EXPORT__RENDER_PRODUCT_EXCEPTION';

    public static function malformedJsonlLine(string $message, int $line): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::JSONL_MALFORMED_LINE_EXCEPTION,
            $message,
            ['line' => $line]
        );
    }

    public static function jsonlLineMustDecodeToObject(int $line): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::JSONL_LINE_NOT_OBJECT_EXCEPTION,
            'Each JSONL line must decode to an object.',
            ['line' => $line]
        );
    }

    public static function renderProductException(string $message): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::RENDER_PRODUCT_EXCEPTION,
            \sprintf('Failed rendering string template using Twig: %s', $message)
        );
    }
}
