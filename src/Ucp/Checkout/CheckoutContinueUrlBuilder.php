<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfigService;

/** @internal */
#[Package('checkout')]
final class CheckoutContinueUrlBuilder implements CheckoutContinueUrlBuilderInterface
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
