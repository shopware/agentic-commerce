<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Content\ProductExport\AgenticProductExportException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(AgenticProductExportException::class)]
class AgenticProductExportExceptionTest extends TestCase
{
    public function testMalformedJsonlLine(): void
    {
        $exception = AgenticProductExportException::malformedJsonlLine('Syntax error', 3);

        static::assertSame(Response::HTTP_BAD_REQUEST, $exception->getStatusCode());
        static::assertSame(AgenticProductExportException::JSONL_MALFORMED_LINE_EXCEPTION, $exception->getErrorCode());
        static::assertSame('Syntax error', $exception->getMessage());
        static::assertSame(['line' => 3], $exception->getParameters());
    }

    public function testJsonlLineMustDecodeToObject(): void
    {
        $exception = AgenticProductExportException::jsonlLineMustDecodeToObject(7);

        static::assertSame(Response::HTTP_BAD_REQUEST, $exception->getStatusCode());
        static::assertSame(AgenticProductExportException::JSONL_LINE_NOT_OBJECT_EXCEPTION, $exception->getErrorCode());
        static::assertSame('Each JSONL line must decode to an object.', $exception->getMessage());
        static::assertSame(['line' => 7], $exception->getParameters());
    }

    public function testRenderProductException(): void
    {
        $exception = AgenticProductExportException::renderProductException('Twig boom');

        static::assertSame(Response::HTTP_BAD_REQUEST, $exception->getStatusCode());
        static::assertSame(AgenticProductExportException::RENDER_PRODUCT_EXCEPTION, $exception->getErrorCode());
        static::assertSame('Failed rendering string template using Twig: Twig boom', $exception->getMessage());
    }
}
