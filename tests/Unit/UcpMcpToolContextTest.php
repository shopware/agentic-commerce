<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Util\Hasher;
use Swag\AgenticCommerce\Ucp\Http\SymfonyRequestContextFactory;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpMcpToolContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\IdempotencyRecord;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;
use Ucp\Sdk\Service\IdempotencyServiceInterface;

/** @internal */
final class UcpMcpToolContextTest extends TestCase
{
    #[Test]
    public function testRequestContextPrefersMainRequestAttribute(): void
    {
        $expectedContext = $this->requestContext();
        $request = Request::create('https://shop.example/ucp/mcp');
        $request->attributes->set(SymfonyRequestContextFactory::REQUEST_CONTEXT_ATTRIBUTE, $expectedContext);

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::never())->method('create');

        $context = new UcpMcpToolContext(
            new SymfonyRequestContextFactory($requestContextFactory),
            $this->createMock(IdempotencyServiceInterface::class),
            $this->requestStack($request),
        );

        self::assertSame($expectedContext, $context->requestContext());
    }

    #[Test]
    public function testRequestContextFallsBackToFactoryWithOriginalRequestData(): void
    {
        $expectedContext = $this->requestContext();

        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (HttpRequest $request): bool {
                self::assertSame(Request::METHOD_PATCH, $request->method);
                self::assertSame('https://shop.example/ucp/mcp?a=1&b=2', $request->absoluteUri);
                self::assertSame(['a' => '1', 'b' => '2'], $request->query);
                self::assertSame('one', $request->headers['x-test']);
                self::assertSame('body', $request->body);

                return true;
            }))
            ->willReturn($expectedContext);

        $context = new UcpMcpToolContext(
            new SymfonyRequestContextFactory($requestContextFactory),
            $this->createMock(IdempotencyServiceInterface::class),
            $this->requestStack(Request::create(
                'https://shop.example/ucp/mcp?b=2&a=1',
                Request::METHOD_PATCH,
                server: ['HTTP_X_TEST' => 'one'],
                content: 'body',
            )),
        );

        self::assertSame($expectedContext, $context->requestContext());
    }

    #[Test]
    public function testExecuteMutatingRequiresIdempotencyKeyWhenConfigured(): void
    {
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');

        $context = $this->toolContext($this->requestContext(idempotencyRequired: true), idempotencyService: $idempotencyService);
        $executed = false;

        try {
            $context->executeMutating('cart.create', [], static function () use (&$executed): array {
                $executed = true;

                return [];
            });
            self::fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame('Idempotency key is required for mutating UCP requests.', $exception->getMessage());
            self::assertSame(['$.headers.idempotency-key is required'], $exception->getViolations());
            self::assertFalse($executed);
        }
    }

    #[Test]
    public function testExecuteMutatingRunsDirectlyWhenNoKeyIsPresentAndIdempotencyIsOptional(): void
    {
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');

        $requestContext = $this->requestContext(idempotencyRequired: false);
        $context = $this->toolContext($requestContext, idempotencyService: $idempotencyService);

        $result = $context->executeMutating(
            'cart.create',
            [],
            static function (RequestContext $context) use ($requestContext): array {
                self::assertSame($requestContext, $context);

                return ['ok' => true];
            },
        );

        self::assertSame(['success' => true, 'data' => ['ok' => true]], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function testExecuteMutatingClaimsAndCompletesIdempotencyRecord(): void
    {
        $fingerprintInput = ['id' => 'cart-id'];
        $fingerprint = Hasher::hash('cart.cancel|'.json_encode($fingerprintInput, \JSON_THROW_ON_ERROR), 'sha256');
        $record = new IdempotencyRecord('idem-key', $fingerprint);

        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::once())
            ->method('claim')
            ->with('idem-key', $fingerprint)
            ->willReturn($record);
        $idempotencyService->expects(self::once())
            ->method('complete')
            ->with($record, ['cancelled' => true], 200);
        $idempotencyService->expects(self::never())->method('abort');

        $context = $this->toolContext(
            $this->requestContext(idempotencyRequired: true, idempotencyKey: 'idem-key'),
            idempotencyService: $idempotencyService,
        );

        $result = $context->executeMutating(
            'cart.cancel',
            $fingerprintInput,
            static fn (): array => ['cancelled' => true],
        );

        self::assertSame(['success' => true, 'data' => ['cancelled' => true]], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function testExecuteMutatingReplaysCompletedResponseWithoutExecuting(): void
    {
        $record = new IdempotencyRecord(
            'idem-key',
            'fingerprint',
            'completed',
            ['stored' => true],
            200,
        );

        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::once())
            ->method('claim')
            ->willReturn($record);
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $context = $this->toolContext(
            $this->requestContext(idempotencyRequired: true, idempotencyKey: 'idem-key'),
            idempotencyService: $idempotencyService,
        );

        $executed = false;
        $result = $context->executeMutating(
            'cart.cancel',
            ['id' => 'cart-id'],
            static function () use (&$executed): array {
                $executed = true;

                return [];
            },
        );

        self::assertFalse($executed);
        self::assertSame(['success' => true, 'data' => ['stored' => true]], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function testExecuteMutatingRejectsCompletedNonReplayableRecord(): void
    {
        $record = new IdempotencyRecord(
            'idem-key',
            'fingerprint',
            'completed',
            ['stored' => true],
            200,
            false,
        );

        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::once())
            ->method('claim')
            ->willReturn($record);
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $context = $this->toolContext(
            $this->requestContext(idempotencyRequired: true, idempotencyKey: 'idem-key'),
            idempotencyService: $idempotencyService,
        );

        $this->expectExceptionObject(new IdempotencyConflictException('Idempotency key refers to a completed response that is no longer replayable.'));

        $context->executeMutating('cart.cancel', ['id' => 'cart-id'], static function (): array {
            self::fail('Completed non-replayable idempotency records must not execute the operation.');
        });
    }

    #[Test]
    public function testExecuteMutatingAbortsIdempotencyRecordWhenExecutionFails(): void
    {
        $record = new IdempotencyRecord('idem-key', 'fingerprint');
        $failure = new \RuntimeException('execution failed');

        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::once())
            ->method('claim')
            ->willReturn($record);
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::once())
            ->method('abort')
            ->with($record);

        $context = $this->toolContext(
            $this->requestContext(idempotencyRequired: true, idempotencyKey: 'idem-key'),
            idempotencyService: $idempotencyService,
        );

        try {
            $context->executeMutating('cart.cancel', ['id' => 'cart-id'], static function () use ($failure): array {
                throw $failure;
            });
            self::fail('Expected the original execution failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    private function toolContext(
        RequestContext $requestContext,
        ?HttpRequestContextFactoryInterface $requestContextFactory = null,
        ?IdempotencyServiceInterface $idempotencyService = null,
    ): UcpMcpToolContext {
        $request = Request::create('https://shop.example/ucp/mcp');
        $request->attributes->set(SymfonyRequestContextFactory::REQUEST_CONTEXT_ATTRIBUTE, $requestContext);

        return new UcpMcpToolContext(
            new SymfonyRequestContextFactory($requestContextFactory ?? $this->requestContextFactory($requestContext)),
            $idempotencyService ?? $this->createMock(IdempotencyServiceInterface::class),
            $this->requestStack($request),
        );
    }

    private function requestContextFactory(RequestContext $requestContext): HttpRequestContextFactoryInterface
    {
        $requestContextFactory = $this->createMock(HttpRequestContextFactoryInterface::class);
        $requestContextFactory->method('create')->willReturn($requestContext);

        return $requestContextFactory;
    }

    private function requestStack(Request $request): RequestStack
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    private function requestContext(bool $idempotencyRequired = true, ?string $idempotencyKey = null): RequestContext
    {
        return new RequestContext(
            'shop.example',
            idempotencyKey: $idempotencyKey,
            runtimeConfiguration: new RuntimeConfiguration(
                '2026-04-08',
                'https://shop.example',
                idempotencyRequired: $idempotencyRequired,
            ),
        );
    }
}
