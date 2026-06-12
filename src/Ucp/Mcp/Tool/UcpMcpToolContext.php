<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Hasher;
use Swag\AgenticCommerce\Ucp\Http\SymfonyRequestContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\IdempotencyRecord;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\IdempotencyServiceInterface;

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
     * @param array<string, mixed>                           $fingerprintInput
     * @param callable(RequestContext): array<string, mixed> $execute
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

        $fingerprint = Hasher::hash($operation.'|'.json_encode($fingerprintInput, \JSON_THROW_ON_ERROR), 'sha256');
        $record = $this->idempotencyService->claim($context->idempotencyKey, $fingerprint);

        if ('completed' === $record->status && !$record->replayable) {
            throw new IdempotencyConflictException('Idempotency key refers to a completed response that is no longer replayable.');
        }

        if ('completed' === $record->status && null !== $record->responseBody) {
            return $this->success($record->responseBody);
        }

        try {
            $data = $execute($context);
        } catch (\Throwable $exception) {
            $this->abortIdempotency($record);

            throw $exception;
        }

        $this->idempotencyService->complete($record, $data, 200);

        return $this->success($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeObject(string $payload): array
    {
        $decoded = '' !== $payload ? json_decode($payload, true, 512, \JSON_THROW_ON_ERROR) : [];

        return \is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
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
     * @param array<string, mixed> $data
     */
    public function success(array $data): string
    {
        return json_encode([
            'success' => true,
            'data' => $data,
        ], \JSON_THROW_ON_ERROR);
    }

    private function abortIdempotency(IdempotencyRecord $record): void
    {
        $this->idempotencyService->abort($record);
    }
}
