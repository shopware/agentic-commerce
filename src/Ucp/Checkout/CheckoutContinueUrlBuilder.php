<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;

final readonly class CheckoutContinueUrlBuilder
{
    public function __construct(
        private UcpConfigService $configService,
    ) {
    }

    public function build(string $checkoutId, string $salesChannelId): ?string
    {
        $config = $this->configService->getConfig($salesChannelId);
        if (null === $config->continueUrlTemplate || '' === $config->continueUrlTemplate) {
            return null;
        }

        return strtr($config->continueUrlTemplate, [
            '{checkoutId}' => $checkoutId,
            '{cartId}' => $checkoutId,
            '{salesChannelId}' => $salesChannelId,
        ]);
    }
}
