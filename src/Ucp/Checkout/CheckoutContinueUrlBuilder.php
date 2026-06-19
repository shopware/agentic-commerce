<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;

final class CheckoutContinueUrlBuilder
{
    public function __construct(
        private readonly UcpConfigService $configService,
    ) {
    }

    public function build(string $checkoutId, string $salesChannelId): ?string
    {
        $config = $this->configService->getConfig($salesChannelId);
        if (null === $config->continueUrlTemplate || '' === $config->continueUrlTemplate) {
            return null;
        }

        return strtr($config->continueUrlTemplate, [
            '{checkoutId}' => rawurlencode($checkoutId),
            '{cartId}' => rawurlencode($checkoutId),
            '{salesChannelId}' => rawurlencode($salesChannelId),
        ]);
    }
}
