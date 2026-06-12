<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Ucp\Sdk\Exception\ValidationException;

final class CheckoutWebhookUrlGuard
{
    public function __construct(
        private readonly SalesChannelViewProvider $salesChannelViewProvider,
    ) {
    }

    public function assertAllowed(string $webhookUrl, UcpConfig $config, string $salesChannelId): void
    {
        $scheme = parse_url($webhookUrl, \PHP_URL_SCHEME);
        $host = parse_url($webhookUrl, \PHP_URL_HOST);

        if (!\is_string($scheme) || !\in_array(strtolower($scheme), ['http', 'https'], true) || !\is_string($host) || '' === $host) {
            throw new ValidationException('Webhook override URLs must use http or https and include a host.', ['$.webhookUrlOverride must be an absolute http(s) URL']);
        }

        $allowedHosts = array_map('strtolower', [] !== $config->agentAllowlist ? $config->agentAllowlist : $config->platformAllowlist);
        if ([] === $allowedHosts) {
            $salesChannelBaseUrl = $this->salesChannelViewProvider->firstDomainUrl($salesChannelId);
            $salesChannelHost = parse_url((string) $salesChannelBaseUrl, \PHP_URL_HOST);

            if (\is_string($salesChannelHost) && '' !== $salesChannelHost) {
                $allowedHosts[] = strtolower($salesChannelHost);
            }
        }

        if ([] === $allowedHosts) {
            throw new ValidationException('Webhook override host validation requires either agentAllowlist, platformAllowlist, or a resolvable sales channel domain.', ['$.webhookUrlOverride host cannot be validated because no allowlist is configured']);
        }

        if (!\in_array(strtolower($host), $allowedHosts, true)) {
            throw new ValidationException(\sprintf('Webhook override host "%s" is not permitted.', $host), ['$.webhookUrlOverride host is not allowlisted']);
        }
    }
}
