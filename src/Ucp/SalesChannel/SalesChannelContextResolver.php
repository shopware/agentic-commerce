<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\SalesChannel;

use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\RequestContext;

final class SalesChannelContextResolver
{
    public function __construct(
        private readonly SalesChannelDomainResolver $domainResolver,
        private readonly SalesChannelContextServiceInterface $contextService,
        private readonly SalesChannelContextPersister $contextPersister,
    ) {
    }

    public function resolve(string $token, RequestContext $requestContext): SalesChannelContext
    {
        $resolution = $this->requireResolution($requestContext);
        $customerId = $this->storedCustomerId($token, $resolution->salesChannelId);

        return $this->contextService->get(new SalesChannelContextServiceParameters(
            $resolution->salesChannelId,
            $token,
            $resolution->languageId,
            $resolution->currencyId,
            $resolution->domainId,
            null,
            $customerId,
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

    private function storedCustomerId(string $token, string $salesChannelId): ?string
    {
        $payload = $this->contextPersister->load($token, $salesChannelId);
        $customerId = $payload['customerId'] ?? null;

        return \is_string($customerId) && '' !== $customerId ? $customerId : null;
    }
}
