<?php

declare(strict_types=1);

namespace Shopware\Core\Framework\Mcp\Result;

// Core ships these from 6.7.16.0. Mirror only the API of that release the UCP tools use, so the
// tests and PHPStan run on older lanes too. A lane that has them keeps its own.
if (!class_exists(McpToolError::class)) {
    final class McpToolError
    {
        public const TOOL_ERROR = 'tool_error';

        public const MISSING_PRIVILEGE = 'missing_privilege';

        public const INVALID_ARGUMENTS = 'invalid_arguments';

        public const NOT_FOUND = 'not_found';

        /**
         * @param array<string, mixed> $details
         */
        public function __construct(
            public readonly string $message,
            public readonly string $code = self::TOOL_ERROR,
            public readonly array $details = [],
        ) {
        }
    }
}

if (!class_exists(McpToolResult::class)) {
    final class McpToolResult
    {
        /**
         * @param array<string, mixed> $meta
         * @param list<mixed>          $links
         */
        public function __construct(
            public readonly mixed $data = null,
            public readonly ?string $summary = null,
            public readonly ?McpToolError $error = null,
            public readonly array $meta = [],
            public readonly ?\DateTimeImmutable $generatedAt = null,
            public readonly ?\DateTimeImmutable $expiresAt = null,
            public readonly array $links = [],
        ) {
        }

        /**
         * @param array<string, mixed> $meta
         */
        public static function success(mixed $data, array $meta = [], ?string $summary = null): self
        {
            return new self(data: $data, summary: $summary, meta: $meta);
        }

        /**
         * @param array<string, mixed> $details
         */
        public static function failure(string $message, string $code = McpToolError::TOOL_ERROR, array $details = []): self
        {
            return new self(error: new McpToolError($message, $code, $details));
        }

        public function isError(): bool
        {
            return null !== $this->error;
        }
    }
}
