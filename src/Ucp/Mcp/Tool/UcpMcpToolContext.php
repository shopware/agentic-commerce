<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

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
     * @param UcpMcpJsonObject                                $fingerprintInput
     * @param callable(RequestContext): UcpMcpOperationResult $execute
     */
    public function executeMutating(string $operation, array $fingerprintInput, callable $execute): string
    {
        $context = $this->requestContext();

        if (true === $context->runtimeConfiguration?->idempotencyRequired && null === $context->idempotencyKey) {
            throw new ValidationException('Idempotency key is required for mutating UCP requests.', ['$.headers.idempotency-key is required']);
        }

        if (null === $context->idempotencyKey) {
            return $this->success($execute($context));
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

            return $this->success($responseBody);
        }

        try {
            $data = $execute($context);
        } catch (\Throwable $exception) {
            $this->abortIdempotency($record);

            throw $exception;
        }

        $data = $this->normalizeData($data);
        $this->idempotencyService->complete($record, $data, 200);

        return $this->success($data);
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
     */
    public function success(array|UcpOperationResponse $data): string
    {
        return json_encode([
            'success' => true,
            'data' => $this->normalizeData($data),
        ], \JSON_THROW_ON_ERROR);
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
