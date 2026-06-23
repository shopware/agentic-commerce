<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\SigningKeyAlgorithm;

/** @internal */
final class SigningKeyAlgorithmTest extends TestCase
{
    #[Test]
    public function testItExposesTheSupportedAlgorithmValues(): void
    {
        self::assertSame(['ES256', 'ES384'], SigningKeyAlgorithm::values());
    }

    #[Test]
    public function testItResolvesKnownAlgorithmsAndRejectsUnknownOnes(): void
    {
        self::assertSame(SigningKeyAlgorithm::ES256, SigningKeyAlgorithm::tryFrom('ES256'));
        self::assertSame(SigningKeyAlgorithm::ES384, SigningKeyAlgorithm::tryFrom('ES384'));
        self::assertNull(SigningKeyAlgorithm::tryFrom('RS256'));
    }
}
