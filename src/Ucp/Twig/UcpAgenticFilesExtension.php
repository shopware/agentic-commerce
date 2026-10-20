<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Twig;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Swag\AgenticCommerce\Ucp\Profile\UcpProfileLocator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** @internal */
#[Package('discovery')]
final class UcpAgenticFilesExtension extends AbstractExtension
{
    public function __construct(
        private readonly UcpConfigService $configService,
        private readonly UcpProfileLocator $profileLocator,
        private readonly SalesChannelDomainResolver $domainResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('swag_agentic_commerce_ucp_active', $this->isUcpActive(...)),
            new TwigFunction('swag_agentic_commerce_ucp_profile_url', $this->ucpProfileUrl(...)),
        ];
    }

    /**
     * Core passes a base URL only to `ai-catalog.json`. Other files fall back to the requested
     * domain, and to the root-relative path when no domain matches, as in an Administration preview.
     */
    public function ucpProfileUrl(?string $baseUrl = null): ?string
    {
        return $this->profileLocator->profileUrl($baseUrl ?? $this->requestedDomainUrl() ?? '');
    }

    public function isUcpActive(SalesChannelEntity|string|null $salesChannel): bool
    {
        $salesChannelId = $salesChannel instanceof SalesChannelEntity ? $salesChannel->getId() : $salesChannel;
        if (!\is_string($salesChannelId) || '' === $salesChannelId) {
            return false;
        }

        return $this->configService->getConfig($salesChannelId)->active;
    }

    private function requestedDomainUrl(): ?string
    {
        $mainRequest = $this->requestStack->getMainRequest();

        return null === $mainRequest ? null : $this->domainResolver->resolveByRequest($mainRequest)?->baseUrl;
    }
}
