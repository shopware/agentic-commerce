<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Http;

use Shopware\Core\Framework\Log\Package;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;

/** @internal */
#[Package('framework')]
final class SymfonyRequestContextFactory
{
    public const REQUEST_CONTEXT_ATTRIBUTE = 'ucp_request_context';

    public function __construct(
        private readonly HttpRequestContextFactoryInterface $requestContextFactory,
    ) {
    }

    public function create(Request $request, ?string $body = null): RequestContext
    {
        return $this->requestContextFactory->create($this->toHttpRequest($request, $body));
    }

    public function createFallback(): RequestContext
    {
        return $this->requestContextFactory->create(new HttpRequest('POST', 'https://example.invalid', [], [], ''));
    }

    public function get(Request $request): ?RequestContext
    {
        $context = $request->attributes->get(self::REQUEST_CONTEXT_ATTRIBUTE);

        return $context instanceof RequestContext ? $context : null;
    }

    public function set(Request $request, RequestContext $context): void
    {
        $request->attributes->set(self::REQUEST_CONTEXT_ATTRIBUTE, $context);
    }

    private function toHttpRequest(Request $request, ?string $body): HttpRequest
    {
        return new HttpRequest(
            $request->getMethod(),
            $request->getUri(),
            $this->headers($request),
            $this->query($request),
            $body ?? $request->getContent(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $value) {
            $headers[$name] = implode(', ', array_map(static fn (?string $entry): string => (string) $entry, $value));
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function query(Request $request): array
    {
        $query = $request->query->all();
        ksort($query);

        return array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : json_encode($value, \JSON_THROW_ON_ERROR), $query);
    }
}
