<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog;
use Swag\AgenticCommerce\Ucp\UcpProtocol;
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;

#[Package('framework')]
final class UcpConfig
{
    /**
     * @param list<string> $enabledCapabilities
     * @param list<string> $enabledTransports
     * @param list<string> $platformAllowlist
     * @param list<string> $remoteProfileAllowlist
     * @param list<string> $agentAllowlist
     * @param list<string> $embeddedAllowedOrigins
     * @param list<string> $embeddedFrameAncestors
     */
    public function __construct(
        public readonly bool $active = false,
        public readonly string $ucpVersion = UcpProtocol::VERSION,
        public readonly string $profileUriStrategy = 'domain',
        public readonly ?string $customProfileUri = null,
        public readonly array $enabledCapabilities = [],
        public readonly array $enabledTransports = ['rest'],
        public readonly ?string $continueUrlTemplate = null,
        public readonly array $platformAllowlist = [],
        public readonly array $remoteProfileAllowlist = [],
        public readonly array $agentAllowlist = [],
        public readonly array $embeddedAllowedOrigins = [],
        public readonly array $embeddedFrameAncestors = [],
        public readonly int $discoveryBudget = 10,
        public readonly ?string $webhookUrlOverride = null,
        public readonly string $signaturePolicy = 'strict',
        public readonly bool $idempotencyRequired = true,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            self::boolValue($payload['active'] ?? false),
            self::stringValue($payload['ucpVersion'] ?? UcpProtocol::VERSION, UcpProtocol::VERSION),
            self::stringValue($payload['profileUriStrategy'] ?? 'domain', 'domain'),
            self::nullableStringValue($payload['customProfileUri'] ?? null),
            self::enabledCapabilityList($payload),
            self::enabledTransportList($payload),
            self::nullableStringValue($payload['continueUrlTemplate'] ?? null),
            self::stringList($payload['platformAllowlist'] ?? []),
            self::stringList($payload['remoteProfileAllowlist'] ?? []),
            self::stringList($payload['agentAllowlist'] ?? []),
            self::stringList($payload['embeddedAllowedOrigins'] ?? []),
            self::stringList($payload['embeddedFrameAncestors'] ?? []),
            self::intValue($payload['discoveryBudget'] ?? 10, 10),
            self::nullableStringValue($payload['webhookUrlOverride'] ?? null),
            self::signaturePolicyValue($payload['signaturePolicy'] ?? 'strict'),
            self::boolValue($payload['idempotencyRequired'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'active' => $this->active,
            'ucpVersion' => $this->ucpVersion,
            'profileUriStrategy' => $this->profileUriStrategy,
            'customProfileUri' => $this->customProfileUri,
            'enabledCapabilities' => $this->enabledCapabilities,
            'enabledTransports' => $this->enabledTransports,
            'continueUrlTemplate' => $this->continueUrlTemplate,
            'platformAllowlist' => $this->platformAllowlist,
            'remoteProfileAllowlist' => $this->remoteProfileAllowlist,
            'agentAllowlist' => $this->agentAllowlist,
            'embeddedAllowedOrigins' => $this->embeddedAllowedOrigins,
            'embeddedFrameAncestors' => $this->embeddedFrameAncestors,
            'discoveryBudget' => $this->discoveryBudget,
            'webhookUrlOverride' => $this->webhookUrlOverride,
            'signaturePolicy' => $this->signaturePolicy,
            'idempotencyRequired' => $this->idempotencyRequired,
        ];
    }

    public function resolveBaseUri(string $fallbackBaseUri): string
    {
        if ('config' === $this->profileUriStrategy && null !== $this->customProfileUri && '' !== $this->customProfileUri) {
            return rtrim($this->customProfileUri, '/');
        }

        return rtrim($fallbackBaseUri, '/');
    }

    /**
     * @return list<string>
     */
    public function runtimeEnabledCapabilityDescriptors(): array
    {
        if (!$this->active) {
            return [];
        }

        return UcpCapabilityCatalog::descriptorNamesForConfigKeys($this->enabledCapabilities);
    }

    /**
     * @return list<Transport>
     */
    public function runtimeTransports(bool $storeApiMcpAvailable = false): array
    {
        $transports = [];

        foreach ($this->enabledTransports as $transport) {
            $transport = Transport::tryFrom($transport);

            if (null === $transport) {
                continue;
            }

            if (Transport::Mcp === $transport && !$storeApiMcpAvailable) {
                continue;
            }

            $transports[$transport->value] = $transport;
        }

        return array_values($transports);
    }

    /**
     * @return array<string, string>
     */
    public function transportEndpoints(string $fallbackBaseUri, bool $storeApiMcpAvailable = false): array
    {
        if (!$storeApiMcpAvailable || !\in_array(Transport::Mcp, $this->runtimeTransports(true), true)) {
            return [];
        }

        return [
            Transport::Mcp->value => $this->resolveBaseUri($fallbackBaseUri).'/ucp/mcp',
        ];
    }

    public function toRuntimeConfiguration(string $fallbackBaseUri, ?string $tenantIdentifier = null, bool $storeApiMcpAvailable = false): RuntimeConfiguration
    {
        $baseUri = $this->resolveBaseUri($fallbackBaseUri);
        $host = parse_url($baseUri, \PHP_URL_HOST);
        $fallbackHosts = [] !== $this->platformAllowlist ? $this->platformAllowlist : (false !== $host && null !== $host ? [(string) $host] : []);
        $allowedProfileHosts = [] !== $this->remoteProfileAllowlist ? $this->remoteProfileAllowlist : $fallbackHosts;
        $allowedAgentDomains = [] !== $this->agentAllowlist ? $this->agentAllowlist : $fallbackHosts;

        return new RuntimeConfiguration(
            $this->ucpVersion,
            $baseUri,
            SignaturePolicy::from($this->signaturePolicy),
            $this->idempotencyRequired,
            $allowedProfileHosts,
            $allowedAgentDomains,
            [],
            $this->runtimeTransports($storeApiMcpAvailable),
            $this->runtimeEnabledCapabilityDescriptors(),
            $tenantIdentifier,
            $this->transportEndpoints($fallbackBaseUri, $storeApiMcpAvailable),
        );
    }

    private static function boolValue(mixed $value): bool
    {
        return \is_bool($value) ? $value : filter_var($value, \FILTER_VALIDATE_BOOL);
    }

    private static function intValue(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function stringValue(mixed $value, string $default): string
    {
        return \is_string($value) && '' !== $value ? $value : $default;
    }

    private static function nullableStringValue(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private static function signaturePolicyValue(mixed $value): string
    {
        if (!\is_string($value) || '' === $value) {
            return 'strict';
        }

        return null !== SignaturePolicy::tryFrom($value) ? $value : 'strict';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private static function enabledCapabilityList(array $payload): array
    {
        if (!\array_key_exists('enabledCapabilities', $payload) || !\is_array($payload['enabledCapabilities'])) {
            return UcpCapabilityCatalog::defaultConfigKeys();
        }

        return self::capabilityList($payload['enabledCapabilities']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private static function enabledTransportList(array $payload): array
    {
        if (!\array_key_exists('enabledTransports', $payload) || !\is_array($payload['enabledTransports'])) {
            return ['rest'];
        }

        return self::transportList($payload['enabledTransports']);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (!\is_string($entry) || '' === $entry) {
                continue;
            }

            $normalized[] = $entry;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    private static function capabilityList(mixed $value): array
    {
        return array_values(array_intersect(self::stringList($value), UcpCapabilityCatalog::allConfigKeys()));
    }

    /**
     * @return list<string>
     */
    private static function transportList(mixed $value): array
    {
        $transports = [];
        foreach (self::stringList($value) as $transport) {
            if (null === Transport::tryFrom($transport)) {
                continue;
            }

            $transports[] = $transport;
        }

        return array_values(array_unique($transports));
    }
}
