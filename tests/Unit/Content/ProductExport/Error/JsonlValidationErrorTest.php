<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Content\ProductExport\Error;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Content\ProductExport\Error\JsonlValidationError;

/**
 * @internal
 */
#[CoversClass(JsonlValidationError::class)]
class JsonlValidationErrorTest extends TestCase
{
    public function testBuildsExpectedErrorPayload(): void
    {
        $error = new JsonlValidationError('export-id', 'Malformed JSON on line 2', 2);

        static::assertSame('jsonl-validation-failedexport-id', $error->getId());
        static::assertSame('jsonl-validation-failed', $error->getMessageKey());
        static::assertSame(
            [
                'error' => 'Malformed JSON on line 2',
                'line' => 2,
            ],
            $error->getParameters()
        );

        $messages = $error->getErrorMessages();

        static::assertCount(1, $messages);
        static::assertSame('Malformed JSON on line 2', $messages[0]->getMessage());
        static::assertSame(2, $messages[0]->getLine());
        static::assertNull($messages[0]->getColumn());
        static::assertSame('The export did not generate a valid JSONL file', $error->getMessage());
    }

    public function testJsonSerializeContainsNormalizedErrorInformation(): void
    {
        $error = new JsonlValidationError('export-id', 'Malformed JSON on line 2', 2);

        $serialized = $error->jsonSerialize();

        static::assertSame('jsonl-validation-failedexport-id', $serialized['key']);
        static::assertSame('jsonl-validation-failed', $serialized['messageKey']);
        static::assertSame('The export did not generate a valid JSONL file', $serialized['message']);
        static::assertCount(1, $serialized['errorMessages']);
    }
}
