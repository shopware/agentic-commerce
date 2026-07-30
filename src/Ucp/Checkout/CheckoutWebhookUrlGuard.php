<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Ucp\Sdk\Exception\ValidationException;

/** @internal */
final class CheckoutWebhookUrlGuard
{
    public function __construct(
        private readonly SalesChannelViewProvider $salesChannelViewProvider,
        private readonly bool $allowHttpLocalWebhookOverride = false,
    ) {
    }

    public function assertAllowed(string $webhookUrl, UcpConfig $config, string $salesChannelId): void
    {
        $scheme = parse_url($webhookUrl, \PHP_URL_SCHEME);
        $host = parse_url($webhookUrl, \PHP_URL_HOST);

        if (!\is_string($scheme) || !$this->isAllowedScheme(strtolower($scheme), $webhookUrl) || !\is_string($host) || '' === $host) {
            throw new ValidationException('Webhook override URLs must use https and include a host.', ['$.webhookUrlOverride must be an absolute https URL']);
        }

        $host = $this->normalizeHost($host);
        $allowedHosts = array_map($this->normalizeHost(...), [] !== $config->agentAllowlist ? $config->agentAllowlist : $config->platformAllowlist);
        if ([] === $allowedHosts) {
            foreach ($this->salesChannelViewProvider->domainUrls($salesChannelId) as $salesChannelBaseUrl) {
                $salesChannelHost = parse_url($salesChannelBaseUrl, \PHP_URL_HOST);

                if (\is_string($salesChannelHost) && '' !== $salesChannelHost) {
                    $allowedHosts[] = $this->normalizeHost($salesChannelHost);
                }
            }
        }

        if ([] === $allowedHosts) {
            throw new ValidationException('Webhook override host validation requires either agentAllowlist, platformAllowlist, or a resolvable sales channel domain.', ['$.webhookUrlOverride host cannot be validated because no allowlist is configured']);
        }

        if (!\in_array($host, $allowedHosts, true)) {
            throw new ValidationException(\sprintf('Webhook override host "%s" is not permitted.', $host), ['$.webhookUrlOverride host is not allowlisted']);
        }
    }

    private function normalizeHost(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return $host;
    }

    private function isAllowedScheme(string $scheme, string $webhookUrl): bool
    {
        if ('https' === $scheme) {
            return true;
        }

        return $this->allowHttpLocalWebhookOverride
            && 'http' === $scheme
            && $this->isLocalWebhookHost($webhookUrl);
    }

    private function isLocalWebhookHost(string $webhookUrl): bool
    {
        $host = parse_url($webhookUrl, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return false;
        }

        $host = $this->normalizeHost($host);

        return 'localhost' === $host
            || str_ends_with($host, '.localhost')
            || '127.0.0.1' === $host
            || '::1' === $host;
    }
}
