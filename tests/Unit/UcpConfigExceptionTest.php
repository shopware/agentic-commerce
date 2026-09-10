<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigException;
use Symfony\Component\HttpFoundation\Response;

/** @internal */
final class UcpConfigExceptionTest extends TestCase
{
    #[Test]
    public function testItBuildsInvalidConfigException(): void
    {
        $exception = UcpConfigException::invalidValue('$.signaturePolicy', 'must be valid');

        self::assertSame(Response::HTTP_BAD_REQUEST, $exception->getStatusCode());
        self::assertSame(UcpConfigException::INVALID_CONFIG, $exception->getErrorCode());
        self::assertSame('Invalid UCP config at $.signaturePolicy: must be valid.', $exception->getMessage());
        self::assertSame(['path' => '$.signaturePolicy'], $exception->getParameters());
    }

    #[Test]
    public function testItBuildsSalesChannelTypeCannotBeActivatedException(): void
    {
        $exception = UcpConfigException::salesChannelTypeCannotBeActivated('feed-channel');

        self::assertSame(Response::HTTP_BAD_REQUEST, $exception->getStatusCode());
        self::assertSame(UcpConfigException::SALES_CHANNEL_TYPE_NOT_SUPPORTED, $exception->getErrorCode());
        self::assertSame(['salesChannelId' => 'feed-channel'], $exception->getParameters());
        self::assertStringContainsString('feed-channel', $exception->getMessage());
    }

    #[Test]
    public function testItBuildsInvalidJsonException(): void
    {
        $exception = UcpConfigException::invalidJsonPayload();

        self::assertSame(Response::HTTP_BAD_REQUEST, $exception->getStatusCode());
        self::assertSame(UcpConfigException::INVALID_JSON, $exception->getErrorCode());
        self::assertSame('Invalid JSON payload.', $exception->getMessage());
    }
}
