<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Psr\Log\AbstractLogger;

/**
 * A logger that keeps what it was told, so a test can assert on it.
 *
 * A mock would do, but the assertion that matters here is that the throwable itself
 * reaches the `exception` context key — the thing Monolog turns into a class, a message
 * and a file:line — and reading that off a recorded array is plainer than expressing it
 * as an expectation.
 *
 * @internal
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
