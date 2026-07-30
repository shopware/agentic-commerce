<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Payment;

final class PaymentAuthorizationResult
{
    public function __construct(
        public readonly bool $authorized,
        public readonly ?string $authorizationId = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
    ) {
    }

    public static function authorized(string $authorizationId): self
    {
        return new self(true, $authorizationId);
    }

    public static function failed(string $code, string $message): self
    {
        return new self(false, null, $code, $message);
    }
}
