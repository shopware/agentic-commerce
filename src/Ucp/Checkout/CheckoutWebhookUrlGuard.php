<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Checkout;

use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelViewProvider;
use Ucp\Sdk\Exception\ValidationException;

/** @internal */
final class CheckoutWebhookUrlGuard
{
    private const TEST_WEBHOOK_CAPTURE_PATH = '/_action/swag-agentic-commerce/test/webhooks';

    public function __construct(
        private readonly SalesChannelViewProvider $salesChannelViewProvider,
        private readonly bool $allowHttpTestWebhookCapture = false,
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
            $salesChannelBaseUrl = $this->salesChannelViewProvider->firstDomainUrl($salesChannelId);
            $salesChannelHost = parse_url((string) $salesChannelBaseUrl, \PHP_URL_HOST);

            if (\is_string($salesChannelHost) && '' !== $salesChannelHost) {
                $allowedHosts[] = $this->normalizeHost($salesChannelHost);
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
        return rtrim(strtolower(trim($host)), '.');
    }

    private function isAllowedScheme(string $scheme, string $webhookUrl): bool
    {
        if ('https' === $scheme) {
            return true;
        }

        return $this->allowHttpTestWebhookCapture
            && 'http' === $scheme
            && self::TEST_WEBHOOK_CAPTURE_PATH === parse_url($webhookUrl, \PHP_URL_PATH);
    }
}
