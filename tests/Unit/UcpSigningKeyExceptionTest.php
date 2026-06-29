<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyException;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
final class UcpSigningKeyExceptionTest extends TestCase
{
    #[Test]
    public function testItBuildsInvalidKidException(): void
    {
        $exception = UcpSigningKeyException::invalidKid('only letters are allowed');

        self::assertSame(Response::HTTP_BAD_REQUEST, $exception->getStatusCode());
        self::assertSame(UcpSigningKeyException::INVALID_KID, $exception->getErrorCode());
        self::assertSame('Invalid signing key id: only letters are allowed.', $exception->getMessage());
    }

    #[Test]
    public function testItBuildsInvalidAlgorithmException(): void
    {
        $exception = UcpSigningKeyException::invalidAlgorithm('RS256', ['ES256', 'ES384']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $exception->getStatusCode());
        self::assertSame(UcpSigningKeyException::INVALID_ALGORITHM, $exception->getErrorCode());
        self::assertSame('Unsupported signing key algorithm "RS256". Allowed values: ES256, ES384.', $exception->getMessage());
        self::assertSame(['algorithm' => 'RS256'], $exception->getParameters());
    }
}
