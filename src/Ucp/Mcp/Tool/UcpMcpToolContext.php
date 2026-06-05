<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Mcp\Tool;

use Mcp\Exception\ToolCallException;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

#[Package('checkout')]
final readonly class UcpMcpToolContext
{
    public function __construct(
        private RuntimeConfigurationResolverInterface $runtimeConfigurationResolver,
        private RequestStack $requestStack,
    ) {
    }

    public function requestContext(): RequestContext
    {
        $request = $this->requestStack->getCurrentRequest();
        $absoluteUri = $request instanceof Request ? $request->getUri() : 'https://example.invalid';
        $host = parse_url($absoluteUri, \PHP_URL_HOST);

        return new RequestContext(
            \is_string($host) ? $host : '',
            runtimeConfiguration: $this->runtimeConfigurationResolver->resolve($this->toHttpRequest($request, $absoluteUri)),
        );
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

    public function toToolCallException(\Throwable $exception): ToolCallException
    {
        if ($exception instanceof ValidationException) {
            $violations = $exception->getViolations();
            $message = [] === $violations
                ? $exception->getMessage()
                : $exception->getMessage().': '.implode('; ', $violations);

            return new ToolCallException($message, previous: $exception);
        }

        if ($exception instanceof UcpException) {
            return new ToolCallException($exception->getMessage(), previous: $exception);
        }

        if ($exception instanceof HttpExceptionInterface) {
            return new ToolCallException($exception->getMessage(), previous: $exception);
        }

        return new ToolCallException('UCP MCP tool execution failed.', previous: $exception);
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

    private function toHttpRequest(?Request $request, string $absoluteUri): HttpRequest
    {
        if (!$request instanceof Request) {
            return new HttpRequest('POST', $absoluteUri, [], [], '');
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $value) {
            $headers[$name] = implode(', ', array_map(static fn (?string $entry): string => (string) $entry, $value));
        }

        return new HttpRequest($request->getMethod(), $absoluteUri, $headers, [], '');
    }
}
