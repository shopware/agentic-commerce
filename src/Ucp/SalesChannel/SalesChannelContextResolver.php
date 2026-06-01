<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\RequestContext;

final readonly class SalesChannelContextResolver
{
    public function __construct(
        private SalesChannelDomainResolver $domainResolver,
        private SalesChannelContextServiceInterface $contextService,
    ) {
    }

    public function resolve(string $token, RequestContext $requestContext): SalesChannelContext
    {
        $resolution = $this->requireResolution($requestContext);

        return $this->contextService->get(new SalesChannelContextServiceParameters(
            $resolution->salesChannelId,
            $token,
            $resolution->languageId,
            $resolution->currencyId,
            $resolution->domainId,
        ));
    }

    public function resolveSalesChannel(RequestContext $requestContext): SalesChannelResolution
    {
        return $this->requireResolution($requestContext);
    }

    private function requireResolution(RequestContext $requestContext): SalesChannelResolution
    {
        $baseUri = $requestContext->runtimeConfiguration?->baseUri;
        if (null === $baseUri) {
            $baseUri = 'https://'.$requestContext->host;
        }

        $resolution = $this->domainResolver->resolveByBaseUri($baseUri);

        if (null !== $resolution) {
            return $resolution;
        }

        throw new \RuntimeException(\sprintf('Could not resolve a Shopware sales channel for host "%s".', $requestContext->host));
    }
}
