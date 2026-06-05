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
use Swag\AgenticCommerce\Content\ProductExport\Error\ProviderValidationError;

/**
 * @internal
 */
#[CoversClass(ProviderValidationError::class)]
class ProviderValidationErrorTest extends TestCase
{
    public function testBuildsExpectedErrorPayloadWithLine(): void
    {
        $error = new ProviderValidationError('export-id', 'open-ai', 'return_policy', 'Return policy is missing.', 4);

        static::assertSame('provider-validation-failedexport-idopen-aireturn_policy4', $error->getId());
        static::assertSame('provider-validation-failed', $error->getMessageKey());
        static::assertSame(
            [
                'provider' => 'open-ai',
                'field' => 'return_policy',
                'error' => 'Return policy is missing.',
                'line' => 4,
            ],
            $error->getParameters()
        );

        $messages = $error->getErrorMessages();

        static::assertCount(1, $messages);
        static::assertSame('Return policy is missing.', $messages[0]->getMessage());
        static::assertSame(4, $messages[0]->getLine());
        static::assertNull($messages[0]->getColumn());
        static::assertSame('The export did not satisfy the provider requirements', $error->getMessage());
    }

    public function testBuildsGlobalIdentifierWhenLineIsMissing(): void
    {
        $error = new ProviderValidationError('export-id', 'open-ai', 'return_policy', 'Return policy is missing.');

        static::assertSame('provider-validation-failedexport-idopen-aireturn_policyglobal', $error->getId());
        static::assertSame(
            [
                'provider' => 'open-ai',
                'field' => 'return_policy',
                'error' => 'Return policy is missing.',
                'line' => null,
            ],
            $error->getParameters()
        );
        static::assertNull($error->getErrorMessages()[0]->getLine());
    }

    public function testJsonSerializeContainsNormalizedErrorInformation(): void
    {
        $error = new ProviderValidationError('export-id', 'open-ai', 'return_policy', 'Return policy is missing.', 4);

        $serialized = $error->jsonSerialize();

        static::assertSame('provider-validation-failedexport-idopen-aireturn_policy4', $serialized['key']);
        static::assertSame('provider-validation-failed', $serialized['messageKey']);
        static::assertSame('The export did not satisfy the provider requirements', $serialized['message']);
        static::assertCount(1, $serialized['errorMessages']);
    }
}
