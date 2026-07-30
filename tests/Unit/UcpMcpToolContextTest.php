<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Http\SymfonyRequestContextFactory;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpMcpToolContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
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
        $fingerprint = hash('sha256', 'cart.cancel|'.json_encode($fingerprintInput, \JSON_THROW_ON_ERROR));
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

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function stringListProvider(): iterable
    {
        yield 'json array' => ['["id-a","id-b"]', ['id-a', 'id-b']];
        yield 'json array with whitespace' => ['  [ "id-a" , "id-b" ]  ', ['id-a', 'id-b']];
        yield 'json object wrapping the list' => ['{"ids":["id-a","id-b"]}', ['id-a', 'id-b']];
        yield 'json array of numbers' => ['[1,2]', ['1', '2']];
        yield 'bare id' => ['id-a', ['id-a']];
        yield 'quoted bare id' => ['"id-a"', ['id-a']];
        yield 'comma separated ids' => ['id-a, id-b', ['id-a', 'id-b']];
        yield 'empty string' => ['', []];
        yield 'blank string' => ['   ', []];
        yield 'empty json array' => ['[]', []];
        yield 'json array with blank entries' => ['["id-a","","  "]', ['id-a']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('stringListProvider')]
    #[Test]
    public function testDecodeStringListAcceptsTheShapesAgentsSend(string $payload, array $expected): void
    {
        self::assertSame($expected, $this->toolContext($this->requestContext())->decodeStringList($payload));
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function invalidStringListProvider(): iterable
    {
        yield 'truncated json array' => ['["id-a",', '$.ids is not valid JSON: '];
        yield 'json object without ids' => ['{"productIds":["id-a"]}', '$.ids must be a JSON array of ids, null given'];
        yield 'nested list' => ['[["id-a"]]', '$.ids[] must contain ids as strings, array given'];
    }

    /**
     * @param non-empty-string $expectedViolationPrefix
     */
    #[DataProvider('invalidStringListProvider')]
    #[Test]
    public function testDecodeStringListRejectsUnusableInputWithAnActionableMessage(string $payload, string $expectedViolationPrefix): void
    {
        $context = $this->toolContext($this->requestContext());

        try {
            $context->decodeStringList($payload);
            self::fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame('The ids parameter must be a JSON array string of ids, for example ["id-a","id-b"].', $exception->getMessage());
            self::assertCount(1, $exception->getViolations());
            self::assertStringStartsWith($expectedViolationPrefix, $exception->getViolations()[0]);
        }
    }

    #[Test]
    public function testDecodeObjectReturnsTheDecodedObject(): void
    {
        $context = $this->toolContext($this->requestContext());

        self::assertSame(['items' => [['id' => 'a']]], $context->decodeObject('{"items":[{"id":"a"}]}'));
        self::assertSame([], $context->decodeObject(''));
        self::assertSame([], $context->decodeObject('  '));
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function invalidObjectProvider(): iterable
    {
        yield 'json array' => ['["a"]', '$.payload must be a JSON object, array given'];
        yield 'json scalar' => ['"a"', '$.payload must be a JSON object, string given'];
        yield 'truncated json object' => ['{"a":', '$.payload is not valid JSON: '];
    }

    /**
     * @param non-empty-string $expectedViolationPrefix
     */
    #[DataProvider('invalidObjectProvider')]
    #[Test]
    public function testDecodeObjectRejectsNonObjectPayloadsInsteadOfSilentlyReturningAnEmptyArray(string $payload, string $expectedViolationPrefix): void
    {
        $context = $this->toolContext($this->requestContext());

        try {
            $context->decodeObject($payload);
            self::fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame('The payload parameter must be a JSON object string, for example {"key":"value"}.', $exception->getMessage());
            self::assertCount(1, $exception->getViolations());
            self::assertStringStartsWith($expectedViolationPrefix, $exception->getViolations()[0]);
        }
    }

    #[Test]
    public function testFailureExposesValidationMessageAndViolations(): void
    {
        $context = $this->toolContext($this->requestContext());

        $result = $context->failure(new ValidationException('UCP-Agent header with a profile URI is required for UCP runtime requests.', ['$.headers.ucp-agent is required']));

        self::assertSame([
            'success' => false,
            'error' => [
                'type' => 'validation',
                'message' => 'UCP-Agent header with a profile URI is required for UCP runtime requests.',
                'violations' => ['$.headers.ucp-agent is required'],
            ],
        ], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function failureTypeProvider(): iterable
    {
        yield 'validation' => [new ValidationException('nope'), 'validation'];
        yield 'signature' => [new SignatureException('nope'), 'signature'];
        yield 'not found' => [new ResourceNotFoundException('nope'), 'not_found'];
        yield 'idempotency conflict' => [new IdempotencyConflictException('nope'), 'idempotency_conflict'];
        yield 'unsupported capability' => [new UnsupportedCapabilityException('nope'), 'unsupported_capability'];
        yield 'configuration' => [new ConfigurationException('nope'), 'configuration'];
        yield 'generic ucp' => [new UcpException('nope'), 'ucp'];
    }

    #[DataProvider('failureTypeProvider')]
    #[Test]
    public function testFailureMapsUcpExceptionsToATypeAndKeepsTheMessage(\Throwable $exception, string $expectedType): void
    {
        $result = $this->toolContext($this->requestContext())->failure($exception);

        self::assertSame([
            'success' => false,
            'error' => ['type' => $expectedType, 'message' => 'nope'],
        ], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function testFailureDoesNotLeakNonUcpExceptionMessages(): void
    {
        $result = $this->toolContext($this->requestContext())->failure(new \RuntimeException('SQLSTATE[42S02]: table "secret" does not exist'));

        self::assertSame([
            'success' => false,
            'error' => ['type' => 'internal', 'message' => 'The tool call failed unexpectedly.'],
        ], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
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
