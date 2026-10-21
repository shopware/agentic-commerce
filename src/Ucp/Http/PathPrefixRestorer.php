<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Http;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
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
            if (null !== $candidateRequest && $candidateRequest->getUri() === $absoluteUri) {
                return SalesChannelDomainResolver::absoluteRequestUri($candidateRequest);
            }
        }

        return $absoluteUri;
    }
}
