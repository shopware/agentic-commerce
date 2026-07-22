<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Validation;

use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\ProtocolValidatorInterface;

/**
 * The generated `checkout.create` schema requires the `line_items` key to be present,
 * even when the caller supplies `cart_id` (from which the adapter already derives the cart).
 * This decorator injects an empty `line_items` for the schema check only; the original
 * payload the operation maps from is untouched. Mirrors the MCP tool's anyOf[line_items, cart_id].
 *
 * @internal
 */
final class CartIdCheckoutCreateProtocolValidator implements ProtocolValidatorInterface
{
    public function __construct(
        private readonly ProtocolValidatorInterface $inner,
    ) {}

    public function validateRequest(string $operation, array $payload, RequestContext $context): void
    {
        if (
            $operation === 'checkout.create'
            && \array_key_exists('cart_id', $payload)
            && !\array_key_exists('line_items', $payload)
        ) {
            $payload['line_items'] = [];
        }

        $this->inner->validateRequest($operation, $payload, $context);
    }

    public function validateResponse(string $operation, array $payload, RequestContext $context): void
    {
        $this->inner->validateResponse($operation, $payload, $context);
    }
}
