<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Protocol;

use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\ProtocolValidatorInterface;

final class EmptyMessagesProtocolValidator implements ProtocolValidatorInterface
{
    public function __construct(
        private readonly ProtocolValidatorInterface $inner,
    ) {
    }

    public function validateRequest(string $operation, array $payload, RequestContext $context): void
    {
        $this->inner->validateRequest($operation, $payload, $context);
    }

    public function validateResponse(string $operation, array $payload, RequestContext $context): void
    {
        $this->inner->validateResponse($operation, $this->withoutEmptySuccessMessages($payload), $context);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withoutEmptySuccessMessages(array $payload): array
    {
        if (($payload['ucp']['status'] ?? null) === 'success' && ($payload['messages'] ?? null) === []) {
            unset($payload['messages']);
        }

        return $payload;
    }
}
