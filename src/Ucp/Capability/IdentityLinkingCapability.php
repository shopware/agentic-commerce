<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Capability;

use Ucp\Sdk\Adapter\IdentityLinkingAdapterInterface;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

final class IdentityLinkingCapability implements IdentityLinkingCapabilityInterface
{
    /** @var list<IdentityLinkingAdapterInterface>|null */
    private ?array $resolvedAdapters = null;

    /**
     * @param iterable<IdentityLinkingAdapterInterface> $adapterIterable
     */
    public function __construct(
        private readonly iterable $adapterIterable,
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return UcpCapabilityCatalog::descriptor(UcpCapabilityCatalog::CONFIG_IDENTITY_LINKING);
    }

    public function getMetadata(RequestContext $context): OAuthMetadata
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING, 'Identity linking capability is disabled for this sales channel.');

        return $this->adapter()->getMetadata($context);
    }

    public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING, 'Identity linking capability is disabled for this sales channel.');

        return $this->adapter()->authorize($request, $context);
    }

    public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse
    {
        CapabilityGuard::assertEnabled($context, UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING, 'Identity linking capability is disabled for this sales channel.');

        return $this->adapter()->issueToken($request, $context);
    }

    private function adapter(): IdentityLinkingAdapterInterface
    {
        $adapter = $this->allAdapters()[0] ?? null;
        if (!$adapter instanceof IdentityLinkingAdapterInterface) {
            throw new UnsupportedCapabilityException('Identity linking requires a Shopware-backed identity adapter.');
        }

        return $adapter;
    }

    /**
     * @return list<IdentityLinkingAdapterInterface>
     */
    private function allAdapters(): array
    {
        if (null === $this->resolvedAdapters) {
            $this->resolvedAdapters = array_values([...$this->adapterIterable]);
        }

        return $this->resolvedAdapters;
    }
}
