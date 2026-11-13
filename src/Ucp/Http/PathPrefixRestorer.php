<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Http;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The SDK builds its requests from the URI without the domain's path prefix. Only the Symfony
 * request it was built from still knows the prefix. That can be the main request while a
 * sub-request is current, as for the MCP tools behind `/ucp/mcp`.
 *
 * @internal
 */
#[Package('framework')]
final class PathPrefixRestorer
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function restore(string $absoluteUri): string
    {
        foreach ([$this->requestStack->getCurrentRequest(), $this->requestStack->getMainRequest()] as $candidateRequest) {
            if (null !== $candidateRequest && $this->isBuiltFrom($candidateRequest, $absoluteUri)) {
                return SalesChannelDomainResolver::absoluteRequestUri($candidateRequest);
            }
        }

        return $absoluteUri;
    }

    private function isBuiltFrom(Request $candidateRequest, string $absoluteUri): bool
    {
        if ($candidateRequest->getUri() === $absoluteUri) {
            return true;
        }

        // The SDK keeps the query as the agent sent it, while getUri() sorts and re-encodes it.
        $query = $candidateRequest->server->get('QUERY_STRING');

        return \is_string($query) && '' !== $query
            && $candidateRequest->getSchemeAndHttpHost().$candidateRequest->getBaseUrl().$candidateRequest->getPathInfo().'?'.$query === $absoluteUri;
    }
}
