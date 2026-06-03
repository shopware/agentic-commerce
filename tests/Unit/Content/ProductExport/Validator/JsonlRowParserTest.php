<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Content\ProductExport\AgenticProductExportException;
use Swag\AgenticCommerce\Content\ProductExport\Validator\JsonlRowParser;

/**
 * @internal
 */
#[CoversClass(JsonlRowParser::class)]
class JsonlRowParserTest extends TestCase
{
    public function testParseReturnsDecodedRowsWithLineNumbers(): void
    {
        $parser = new JsonlRowParser();

        $rows = $parser->parse("{\"id\":\"first\"}\n{\"id\":\"second\",\"nested\":{\"foo\":\"bar\"}}\n");

        static::assertSame(
            [
                ['line' => 1, 'row' => ['id' => 'first']],
                ['line' => 2, 'row' => ['id' => 'second', 'nested' => ['foo' => 'bar']]],
            ],
            $rows
        );
    }

    public function testParseSkipsEmptyLines(): void
    {
        $parser = new JsonlRowParser();

        $rows = $parser->parse("\n  \n{\"id\":\"first\"}\n\n\t\n{\"id\":\"second\"}\n");

        static::assertSame(
            [
                ['line' => 3, 'row' => ['id' => 'first']],
                ['line' => 6, 'row' => ['id' => 'second']],
            ],
            $rows
        );
    }

    public function testParseThrowsExceptionForMalformedJsonlLine(): void
    {
        $parser = new JsonlRowParser();

        try {
            $parser->parse("{\"id\":\"first\"}\n{\"id\": }\n");
            static::fail('Expected exception was not thrown.');
        } catch (AgenticProductExportException $exception) {
            static::assertSame(AgenticProductExportException::JSONL_MALFORMED_LINE_EXCEPTION, $exception->getErrorCode());
            static::assertSame(['line' => 2], $exception->getParameters());
        }
    }

    public function testParseThrowsExceptionWhenJsonlLineDoesNotDecodeToObject(): void
    {
        $parser = new JsonlRowParser();

        try {
            $parser->parse("{\"id\":\"first\"}\n\"second\"\n");
            static::fail('Expected exception was not thrown.');
        } catch (AgenticProductExportException $exception) {
            static::assertSame(AgenticProductExportException::JSONL_LINE_NOT_OBJECT_EXCEPTION, $exception->getErrorCode());
            static::assertSame('Each JSONL line must decode to an object.', $exception->getMessage());
            static::assertSame(['line' => 2], $exception->getParameters());
        }
    }
}
