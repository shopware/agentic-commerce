<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Twig;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** @internal */
#[Package('discovery')]
final class UcpAgenticFilesExtension extends AbstractExtension
{
    public function __construct(private readonly UcpConfigService $configService)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('swag_agentic_commerce_ucp_active', $this->isUcpActive(...)),
        ];
    }

    public function isUcpActive(SalesChannelEntity|string|null $salesChannel): bool
    {
        $salesChannelId = $salesChannel instanceof SalesChannelEntity ? $salesChannel->getId() : $salesChannel;
        if (!\is_string($salesChannelId) || '' === $salesChannelId) {
            return false;
        }

        return $this->configService->getConfig($salesChannelId)->active;
    }
}
