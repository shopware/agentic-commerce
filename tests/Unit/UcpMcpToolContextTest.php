<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swag\AgenticCommerce\Ucp\Http\SymfonyRequestContextFactory;
use Swag\AgenticCommerce\Ucp\Mcp\Tool\UcpMcpToolContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Ucp\Sdk\Exception\AgentProfileException;
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
#[CoversClass(UcpMcpToolContext::class)]
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
            $this->createMock(Connection::class),
            new NullLogger(),
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
            $this->createMock(Connection::class),
            new NullLogger(),
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

        self::assertSame(['success' => true, 'dryRun' => false, 'data' => ['ok' => true]], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
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

        self::assertSame(['success' => true, 'dryRun' => false, 'data' => ['cancelled' => true]], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
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
        self::assertSame(['success' => true, 'dryRun' => false, 'data' => ['stored' => true]], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
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

    #[Test]
    public function testDecodeObjectAcceptsTheEmptyObjectEveryToolDefaultsTo(): void
    {
        // Every UCP tool declares `string $payload = '{}'`, so this is the value
        // that arrives whenever an agent omits the payload — which the tool
        // descriptions explicitly invite ("Omit it to charge the sales channel
        // default (invoice/offline) method, which needs nothing from the buyer").
        //
        // It used to throw `$.payload must be a JSON object, array given`, because
        // json_decode('{}', true) yields [] and array_is_list([]) is true. The
        // default failed its own validation, so checkout-complete could not be
        // called without a payload at all.
        $context = $this->toolContext($this->requestContext());

        self::assertSame([], $context->decodeObject('{}'));
        self::assertSame([], $context->decodeObject('  {}  '));
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
                'code' => 'invalid_request',
                'severity' => 'recoverable',
                'violations' => ['$.headers.ucp-agent is required'],
            ],
        ], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{\Throwable, string, string, string}>
     */
    public static function failureTypeProvider(): iterable
    {
        yield 'validation' => [new ValidationException('nope'), 'validation', 'invalid_request', 'recoverable'];
        yield 'signature' => [new SignatureException('nope'), 'signature', 'signature_invalid', 'unrecoverable'];
        yield 'not found' => [new ResourceNotFoundException('nope'), 'not_found', 'not_found', 'unrecoverable'];
        yield 'idempotency conflict' => [new IdempotencyConflictException('nope'), 'idempotency_conflict', 'idempotency_conflict', 'unrecoverable'];
        yield 'unsupported capability' => [new UnsupportedCapabilityException('nope'), 'unsupported_capability', 'capability_unsupported', 'unrecoverable'];
        yield 'configuration' => [new ConfigurationException('nope'), 'configuration', 'server_misconfigured', 'unrecoverable'];
        yield 'generic ucp' => [new UcpException('nope'), 'ucp', 'request_failed', 'unrecoverable'];
    }

    #[DataProvider('failureTypeProvider')]
    #[Test]
    public function testFailureMapsUcpExceptionsToATypeAndKeepsTheMessage(\Throwable $exception, string $expectedType, string $expectedCode, string $expectedSeverity): void
    {
        $result = $this->toolContext($this->requestContext())->failure($exception);

        // `code` and `severity` come from the same descriptor the SDK's HTTP listener
        // reads, so an agent gets the machine-readable part of the failure over MCP too
        // rather than only prose it has to parse.
        self::assertSame([
            'success' => false,
            'error' => ['type' => $expectedType, 'message' => 'nope', 'code' => $expectedCode, 'severity' => $expectedSeverity],
        ], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function testFailureReportsAnUnreachableAgentProfileAsTheRecoverableFailureItIs(): void
    {
        $exception = AgentProfileException::unreachable('http://shop.localhost:8088/.well-known/ucp', new \RuntimeException('Connection refused.'));

        $result = $this->toolContext($this->requestContext())->failure($exception);

        // This is the failure the store suite spent a session on: over REST it answered
        // 424 `agent_profile_unreachable` `recoverable`, over MCP it was `internal` with
        // no code at all, so the two transports described the same exception differently.
        $error = json_decode($result, true, flags: \JSON_THROW_ON_ERROR)['error'];
        self::assertSame('agent_profile_unreachable', $error['code']);
        self::assertSame('recoverable', $error['severity']);
        self::assertStringContainsString('could not be fetched', $error['message']);
    }

    #[Test]
    public function testFailurePassesThroughTheMessageOfAShopwareClientError(): void
    {
        // Shopware's own exceptions are HttpExceptionInterface, not UcpException, so
        // they used to answer `internal` with the message hidden — which is how
        // "Customer is not logged in." reached an agent as "The tool call failed
        // unexpectedly." while the same operation over REST answered 403 and said so.
        $result = $this->toolContext($this->requestContext())->failure(
            new AccessDeniedHttpException('Customer is not logged in.'),
        );

        self::assertSame([
            'success' => false,
            'error' => [
                'type' => 'request',
                'message' => 'Customer is not logged in.',
                'code' => 'invalid_request',
                'severity' => 'recoverable',
            ],
        ], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function testFailureStillHidesTheMessageOfAServerSideHttpException(): void
    {
        // A 4xx message is written for the caller; a 5xx one is written for an
        // operator, and this client is unauthenticated.
        $result = $this->toolContext($this->requestContext())->failure(
            new ServiceUnavailableHttpException(null, 'Database "shop_prod" on db-01:3306 is not reachable.'),
        );

        $error = json_decode($result, true, flags: \JSON_THROW_ON_ERROR)['error'];
        self::assertSame('internal', $error['type']);
        self::assertSame('The tool call failed unexpectedly.', $error['message']);
    }

    #[Test]
    public function testFailureDoesNotLeakNonUcpExceptionMessages(): void
    {
        $result = $this->toolContext($this->requestContext())->failure(new \RuntimeException('SQLSTATE[42S02]: table "secret" does not exist'));

        self::assertSame([
            'success' => false,
            'error' => [
                'type' => 'internal',
                'message' => 'The tool call failed unexpectedly.',
                'code' => 'internal_error',
                'severity' => 'unrecoverable',
            ],
        ], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function testFailureLogsTheThrowableItRefusesToShowTheClient(): void
    {
        $exception = new \RuntimeException('SQLSTATE[42S02]: table "secret" does not exist');
        $logger = new CollectingLogger();

        $result = $this->toolContext($this->requestContext(), logger: $logger)->failure($exception);

        // The generic response is deliberate; the exception vanishing with it was not.
        // Without this the only way to see the cause was to call the same operation over
        // REST, where the SDK's listener does not swallow it.
        self::assertSame('The tool call failed unexpectedly.', json_decode($result, true, flags: \JSON_THROW_ON_ERROR)['error']['message']);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('UCP MCP tool call failed.', $logger->records[0]['message']);
        self::assertSame($exception, $logger->records[0]['context']['exception'] ?? null);
    }

    #[Test]
    public function testFailureLogsDomainErrorsToo(): void
    {
        $logger = new CollectingLogger();

        $this->toolContext($this->requestContext(), logger: $logger)->failure(new ValidationException('nope', ['$.id is required']));

        // A domain error is the agent's to fix, but an operator watching a lane that
        // "does nothing" still needs to see that a call arrived and why it was refused.
        self::assertCount(1, $logger->records);
    }

    #[Test]
    public function testDryRunRunsTheOperationInsideARolledBackTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $requestContext = $this->requestContext(idempotencyRequired: false);
        $executed = false;

        $result = $this->toolContext($requestContext, connection: $connection)->executeMutating(
            'cart.create',
            [],
            static function (RequestContext $context) use ($requestContext, &$executed): array {
                self::assertSame($requestContext, $context);
                $executed = true;

                return ['id' => 'cart-id'];
            },
            true,
        );

        self::assertTrue($executed, 'A dry run must still run the operation, otherwise it validates nothing.');
        self::assertSame(
            ['success' => true, 'dryRun' => true, 'data' => ['id' => 'cart-id']],
            json_decode($result, true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function testDryRunNeverClaimsTheIdempotencyKey(): void
    {
        // Claiming on a preview would make the following real call replay the
        // rolled-back preview response instead of committing.
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $result = $this->toolContext(
            $this->requestContext(idempotencyRequired: true, idempotencyKey: 'idem-key'),
            idempotencyService: $idempotencyService,
        )->executeMutating('cart.cancel', ['id' => 'cart-id'], static fn (): array => ['cancelled' => true], true);

        self::assertSame(
            ['success' => true, 'dryRun' => true, 'data' => ['cancelled' => true]],
            json_decode($result, true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function testDryRunStillRequiresAnIdempotencyKeyWhenConfigured(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('beginTransaction');

        $context = $this->toolContext($this->requestContext(idempotencyRequired: true), connection: $connection);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Idempotency key is required for mutating UCP requests.');

        $context->executeMutating('cart.create', [], static function (): array {
            self::fail('A dry run must fail the same validation a commit would.');
        }, true);
    }

    #[Test]
    public function testDryRunWithAPreviewStillRequiresAnIdempotencyKeyWhenConfigured(): void
    {
        // A tool that previews instead of rolling back — checkout.complete — must not
        // buy itself an exemption from the check by taking its own dry-run branch.
        $context = $this->toolContext($this->requestContext(idempotencyRequired: true));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Idempotency key is required for mutating UCP requests.');

        $context->executeMutating(
            'checkout.complete',
            ['id' => 'checkout-id'],
            static function (): array {
                self::fail('A dry run must not execute the operation.');
            },
            true,
            static function (): string {
                self::fail('A preview must fail the same validation a commit would.');
            },
        );
    }

    #[Test]
    public function testDryRunUsesTheSuppliedPreviewInsteadOfARolledBackTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('beginTransaction');

        $requestContext = $this->requestContext(idempotencyRequired: false);

        $result = $this->toolContext($requestContext, connection: $connection)->executeMutating(
            'checkout.complete',
            ['id' => 'checkout-id'],
            static function (): array {
                self::fail('A previewed operation must never run: its webhook cannot be rolled back.');
            },
            true,
            static function (RequestContext $context) use ($requestContext): string {
                self::assertSame($requestContext, $context, 'The preview must receive the checked context.');

                return '{"success":true,"dryRun":true,"preview":{}}';
            },
        );

        self::assertSame('{"success":true,"dryRun":true,"preview":{}}', $result);
    }

    #[Test]
    public function testDryRunWithAPreviewNeverClaimsTheIdempotencyKey(): void
    {
        $idempotencyService = $this->createMock(IdempotencyServiceInterface::class);
        $idempotencyService->expects(self::never())->method('claim');
        $idempotencyService->expects(self::never())->method('complete');
        $idempotencyService->expects(self::never())->method('abort');

        $result = $this->toolContext(
            $this->requestContext(idempotencyRequired: true, idempotencyKey: 'idem-key'),
            idempotencyService: $idempotencyService,
        )->executeMutating(
            'checkout.complete',
            ['id' => 'checkout-id'],
            static fn (): array => ['completed' => true],
            true,
            static fn (): string => '{"previewed":true}',
        );

        self::assertSame('{"previewed":true}', $result);
    }

    #[Test]
    public function testDryRunRollsBackAndRethrowsWhenTheOperationFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');

        $failure = new ValidationException('line item is unknown');

        try {
            $this->toolContext($this->requestContext(idempotencyRequired: false), connection: $connection)
                ->executeMutating('cart.update', [], static fn (): array => throw $failure, true);
            self::fail('Expected the original failure.');
        } catch (ValidationException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    #[Test]
    public function testDryRunReportsAFailedRollbackInsteadOfClaimingNothingWasCommitted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('rollBack')->willThrowException(new \RuntimeException('deadlock'));

        $context = $this->toolContext($this->requestContext(idempotencyRequired: false), connection: $connection);

        try {
            $context->executeMutating('cart.create', [], static fn (): array => ['id' => 'cart-id'], true);
            self::fail('Expected a failed rollback to be reported.');
        } catch (UcpException $exception) {
            self::assertSame('Dry-run rollback failed, so the operation may have been committed: deadlock', $exception->getMessage());
        }
    }

    #[Test]
    public function testSuccessOmitsTheDryRunFlagForReadOnlyTools(): void
    {
        $result = $this->toolContext($this->requestContext())->success(['products' => []]);

        self::assertSame(['success' => true, 'data' => ['products' => []]], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function testPreviewReportsWhatACommitWouldDoWithoutCommitting(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('beginTransaction');

        $result = $this->toolContext($this->requestContext(), connection: $connection)->preview(
            'checkout.complete',
            ['id' => 'checkout-id', 'status' => 'incomplete'],
            ['Checkout is incomplete.'],
        );

        self::assertSame([
            'success' => true,
            'dryRun' => true,
            'preview' => [
                'operation' => 'checkout.complete',
                'committed' => false,
                'wouldSucceed' => false,
                'blockers' => ['Checkout is incomplete.'],
            ],
            'data' => ['id' => 'checkout-id', 'status' => 'incomplete'],
        ], json_decode($result, true, flags: \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function testPreviewWithoutBlockersReportsThatACommitWouldSucceed(): void
    {
        $result = $this->toolContext($this->requestContext())->preview(
            'checkout.complete',
            ['id' => 'checkout-id', 'status' => 'ready_for_complete'],
        );

        $payload = json_decode($result, true, flags: \JSON_THROW_ON_ERROR);

        self::assertTrue($payload['preview']['wouldSucceed']);
        self::assertSame([], $payload['preview']['blockers']);
        self::assertFalse($payload['preview']['committed']);
    }

    private function toolContext(
        RequestContext $requestContext,
        ?HttpRequestContextFactoryInterface $requestContextFactory = null,
        ?IdempotencyServiceInterface $idempotencyService = null,
        ?Connection $connection = null,
        ?LoggerInterface $logger = null,
    ): UcpMcpToolContext {
        $request = Request::create('https://shop.example/ucp/mcp');
        $request->attributes->set(SymfonyRequestContextFactory::REQUEST_CONTEXT_ATTRIBUTE, $requestContext);

        return new UcpMcpToolContext(
            new SymfonyRequestContextFactory($requestContextFactory ?? $this->requestContextFactory($requestContext)),
            $idempotencyService ?? $this->createMock(IdempotencyServiceInterface::class),
            $this->requestStack($request),
            $connection ?? $this->createMock(Connection::class),
            $logger ?? new NullLogger(),
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
