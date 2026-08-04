<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Http\SymfonyRequestContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Exception\NegotiationException;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\IdempotencyRecord;
use Ucp\Sdk\Model\Protocol\UcpOperationResponse;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\IdempotencyServiceInterface;

/**
 * @phpstan-type UcpMcpJsonScalar bool|float|int|string|null
 * @phpstan-type UcpMcpJsonLevel3 UcpMcpJsonScalar|array<array-key, UcpMcpJsonScalar>
 * @phpstan-type UcpMcpJsonLevel2 UcpMcpJsonScalar|array<array-key, UcpMcpJsonLevel3>
 * @phpstan-type UcpMcpJsonValue UcpMcpJsonScalar|array<array-key, UcpMcpJsonLevel2>
 * @phpstan-type UcpMcpNestedJsonObject array<string, UcpMcpJsonLevel2>
 * @phpstan-type UcpMcpJsonObject array<string, UcpMcpJsonValue>
 * @phpstan-type UcpMcpOperationResult UcpMcpJsonObject|UcpOperationResponse
 *
 * @internal
 */
#[Package('checkout')]
final class UcpMcpToolContext
{
    public function __construct(
        private readonly SymfonyRequestContextFactory $requestContextFactory,
        private readonly IdempotencyServiceInterface $idempotencyService,
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
    ) {
    }

    public function requestContext(): RequestContext
    {
        $mainRequest = $this->requestStack->getMainRequest();
        if ($mainRequest instanceof Request) {
            $context = $this->requestContextFactory->get($mainRequest);
            if ($context instanceof RequestContext) {
                return $context;
            }

            return $this->requestContextFactory->create($mainRequest);
        }

        $currentRequest = $this->requestStack->getCurrentRequest();
        if ($currentRequest instanceof Request) {
            return $this->requestContextFactory->create($currentRequest);
        }

        return $this->requestContextFactory->createFallback();
    }

    /**
     * Single entry point for every mutating tool, dry run or not.
     *
     * A tool that cannot preview by rolling a transaction back passes $preview
     * instead of branching before this method — see UcpCheckoutCompleteTool. Going
     * through here is what keeps the validation below from being skipped on the
     * exact path where skipping it matters most.
     *
     * @param UcpMcpJsonObject                                $fingerprintInput
     * @param callable(RequestContext): UcpMcpOperationResult $execute
     * @param (callable(RequestContext): string)|null         $preview          renders a read-only preview for a tool whose effects a rollback does not undo
     */
    public function executeMutating(string $operation, array $fingerprintInput, callable $execute, bool $dryRun = false, ?callable $preview = null): string
    {
        $context = $this->requestContext();

        // Validation applies to a dry run as well — the point of a preview is to
        // fail on the same input a commit would fail on.
        if (true === $context->runtimeConfiguration?->idempotencyRequired && null === $context->idempotencyKey) {
            throw new ValidationException('Idempotency key is required for mutating UCP requests.', ['$.headers.idempotency-key is required']);
        }

        if ($dryRun) {
            // Deliberately before claim(): a preview must not consume the
            // idempotency key, or the following real call would replay the
            // rolled-back preview instead of committing.
            return null !== $preview
                ? $preview($context)
                : $this->previewMutation($execute, $context);
        }

        if (null === $context->idempotencyKey) {
            return $this->success($execute($context), false);
        }

        // Keep native hash while 6.5 is supported: Shopware\Core\Framework\Util\Hasher
        // exists only in 6.6+/trunk. Switch to Hasher::hash() after dropping 6.5.
        // @phpstan-ignore-next-line shopware.hasher
        $fingerprint = hash('sha256', $operation.'|'.json_encode($fingerprintInput, \JSON_THROW_ON_ERROR));
        $record = $this->idempotencyService->claim($context->idempotencyKey, $fingerprint);

        if ('completed' === $record->status && !$record->replayable) {
            throw new IdempotencyConflictException('Idempotency key refers to a completed response that is no longer replayable.');
        }

        if ('completed' === $record->status && null !== $record->responseBody) {
            /** @var UcpMcpJsonObject $responseBody */
            $responseBody = $record->responseBody;

            return $this->success($responseBody, false);
        }

        try {
            $data = $execute($context);
        } catch (\Throwable $exception) {
            $this->abortIdempotency($record);

            throw $exception;
        }

        $data = $this->normalizeData($data);
        $this->idempotencyService->complete($record, $data, 200);

        return $this->success($data, false);
    }

    /**
     * Renders a read-only preview of a mutating operation the tool declined to run.
     *
     * Used where rolling a commit back is not enough to undo it — `checkout.complete`
     * synchronously POSTs an `order.created` webhook to the merchant, and no database
     * rollback recalls that. The tool reads current state instead and reports what a
     * commit would do, so nothing outside the database is touched. It is reached
     * through executeMutating()'s $preview callback, not around it, so the same
     * validation applies.
     *
     * @param UcpMcpOperationResult $data
     * @param list<string>          $blockers
     */
    public function preview(string $operation, array|UcpOperationResponse $data, array $blockers = []): string
    {
        return json_encode([
            'success' => true,
            'dryRun' => true,
            'preview' => [
                'operation' => $operation,
                'committed' => false,
                'wouldSucceed' => [] === $blockers,
                'blockers' => $blockers,
            ],
            'data' => $this->normalizeData($data),
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Runs a mutating operation inside a transaction that is always rolled back.
     *
     * Same mechanism as the core admin write tools (Shopware's
     * McpToolResponse::executeWithDryRun): the operation runs for real so the agent
     * gets genuine validation and a genuine result shape, then the transaction is
     * discarded. This only covers database state — see docs/mcp-dry-run.md for the
     * side effects it cannot undo, which is why `checkout.complete` supplies a
     * preview() callback and takes this branch's other arm instead.
     *
     * @param callable(RequestContext): UcpMcpOperationResult $execute
     */
    private function previewMutation(callable $execute, RequestContext $context): string
    {
        $this->connection->beginTransaction();

        try {
            $data = $execute($context);
        } catch (\Throwable $exception) {
            $this->rollBackPreview();

            throw $exception;
        }

        $rollbackError = $this->rollBackPreview();
        if (null !== $rollbackError) {
            throw new UcpException(\sprintf('Dry-run rollback failed, so the operation may have been committed: %s', $rollbackError));
        }

        return $this->success($data, true);
    }

    /**
     * @return string|null the failure reason, or null when the rollback succeeded
     */
    private function rollBackPreview(): ?string
    {
        try {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            return null;
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * @return UcpMcpNestedJsonObject
     */
    public function decodeObject(string $payload): array
    {
        $payload = trim($payload);

        if ('' === $payload) {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ValidationException('The payload parameter must be a JSON object string, for example {"key":"value"}.', ['$.payload is not valid JSON: '.$exception->getMessage()]);
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new ValidationException('The payload parameter must be a JSON object string, for example {"key":"value"}.', ['$.payload must be a JSON object, '.get_debug_type($decoded).' given']);
        }

        /* @var UcpMcpNestedJsonObject $decoded */
        return $decoded;
    }

    /**
     * Decodes the id list of a read tool.
     *
     * Accepts what an agent realistically sends for a string parameter that
     * carries a list: a JSON array string, a JSON object wrapping the list, a
     * bare id, or a comma-separated list of ids. Anything else fails loudly
     * instead of silently degrading to an empty list.
     *
     * @return list<string>
     */
    public function decodeStringList(string $payload): array
    {
        $payload = trim($payload);

        if ('' === $payload) {
            return [];
        }

        if (!str_starts_with($payload, '[') && !str_starts_with($payload, '{')) {
            return $this->splitList($payload);
        }

        try {
            $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ValidationException('The ids parameter must be a JSON array string of ids, for example ["id-a","id-b"].', ['$.ids is not valid JSON: '.$exception->getMessage()]);
        }

        if (\is_array($decoded) && !array_is_list($decoded)) {
            $decoded = $decoded['ids'] ?? null;
        }

        if (!\is_array($decoded)) {
            throw new ValidationException('The ids parameter must be a JSON array string of ids, for example ["id-a","id-b"].', ['$.ids must be a JSON array of ids, '.get_debug_type($decoded).' given']);
        }

        $ids = [];
        foreach ($decoded as $id) {
            if (!\is_string($id) && !\is_int($id)) {
                throw new ValidationException('The ids parameter must be a JSON array string of ids, for example ["id-a","id-b"].', ['$.ids[] must contain ids as strings, '.get_debug_type($id).' given']);
            }

            $id = trim((string) $id);
            if ('' !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Renders a tool failure as an in-band result so the calling agent can act on it.
     *
     * The MCP server turns a thrown exception into a generic JSON-RPC tool error
     * ("Error while executing tool"), which strips the message the agent needs to
     * correct its call. Returning the failure as tool content keeps the message —
     * and any validation violations — visible, which is also what the MCP spec
     * recommends for tool-execution errors. Only UCP domain errors are surfaced
     * verbatim; anything else is reported generically so internals do not leak to
     * an unauthenticated MCP client.
     */
    public function failure(\Throwable $exception): string
    {
        $error = $exception instanceof UcpException
            ? ['type' => $this->errorType($exception), 'message' => $exception->getMessage()]
            : ['type' => 'internal', 'message' => 'The tool call failed unexpectedly.'];

        if ($exception instanceof ValidationException && [] !== $exception->getViolations()) {
            $error['violations'] = $exception->getViolations();
        }

        return json_encode([
            'success' => false,
            'error' => $error,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param UcpMcpOperationResult $data
     * @param bool|null             $dryRun whether the caller mutated state; null for read-only tools, which never do
     */
    public function success(array|UcpOperationResponse $data, ?bool $dryRun = null): string
    {
        $payload = ['success' => true];

        if (null !== $dryRun) {
            $payload['dryRun'] = $dryRun;
        }

        $payload['data'] = $this->normalizeData($data);

        return json_encode($payload, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function splitList(string $payload): array
    {
        $ids = [];
        foreach (explode(',', $payload) as $id) {
            $id = trim($id, " \t\n\r\0\x0B\"'");
            if ('' !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function errorType(UcpException $exception): string
    {
        return match (true) {
            $exception instanceof ValidationException => 'validation',
            $exception instanceof SignatureException => 'signature',
            $exception instanceof ResourceNotFoundException => 'not_found',
            $exception instanceof IdempotencyConflictException => 'idempotency_conflict',
            $exception instanceof NegotiationException => 'negotiation',
            $exception instanceof UnsupportedCapabilityException => 'unsupported_capability',
            $exception instanceof ConfigurationException => 'configuration',
            default => 'ucp',
        };
    }

    /**
     * @param UcpMcpOperationResult $data
     *
     * @return array<string, mixed>
     */
    private function normalizeData(array|UcpOperationResponse $data): array
    {
        return $data instanceof UcpOperationResponse ? $data->jsonSerialize() : $data;
    }

    private function abortIdempotency(IdempotencyRecord $record): void
    {
        $this->idempotencyService->abort($record);
    }
}
