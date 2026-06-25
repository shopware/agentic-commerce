<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Http\SymfonyRequestContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Exception\IdempotencyConflictException;
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
     * @param UcpMcpJsonObject                           $fingerprintInput
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
        $decoded = '' !== $payload ? json_decode($payload, true, 512, \JSON_THROW_ON_ERROR) : [];

        if (!\is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        /* @var UcpMcpNestedJsonObject $decoded */
        return $decoded;
    }

    /**
     * @return list<string>
     */
    public function decodeStringList(string $payload): array
    {
        $decoded = '' !== $payload ? json_decode($payload, true, 512, \JSON_THROW_ON_ERROR) : [];

        return array_values(array_map('strval', \is_array($decoded) ? $decoded : []));
    }

    /**
     * Normalises a tool failure before it bubbles up to the MCP server.
     *
     * Intentionally a pass-through for now: the pinned mcp/sdk ^0.5 (via
     * symfony/mcp-bundle in shopware trunk) does not ship
     * Mcp\Exception\ToolCallException, so the original exception propagates and
     * the server returns a generic tool error. Once mcp/sdk ^0.6 is available,
     * map failures to a ToolCallException here (per-violation messages and
     * -32602 for invalid input). See docs/mcp-sdk-upgrade.md.
     */
    public function toToolCallException(\Throwable $exception): \Throwable
    {
        return $exception;
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
