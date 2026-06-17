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
     * String allowlists mirror the scalar admin JSON/stored config contract
     * across supported Shopware lanes.
     *
     * @var list<string>
     */
    private const PROFILE_URI_STRATEGIES = ['domain', 'config'];

    /**
     * @var list<string>
     */
    private const URL_SCHEMES = ['http', 'https'];

    /**
     * @var list<string>
     */
    private const CONTINUE_URL_PLACEHOLDERS = ['{checkoutId}', '{cartId}', '{salesChannelId}'];

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
        public readonly int $catalogResultLimit = 50,
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
        $profileUriStrategy = self::profileUriStrategyValue($payload['profileUriStrategy'] ?? 'domain');
        $customProfileUri = self::nullableHttpUrlValue($payload['customProfileUri'] ?? null, '$.customProfileUri');
        if ('config' === $profileUriStrategy && null === $customProfileUri) {
            throw self::invalid('$.customProfileUri', 'must be set when profileUriStrategy is "config"');
        }

        $platformAllowlist = self::hostList($payload['platformAllowlist'] ?? null, '$.platformAllowlist');
        $remoteProfileAllowlist = self::hostList($payload['remoteProfileAllowlist'] ?? null, '$.remoteProfileAllowlist');
        $agentAllowlist = self::hostList($payload['agentAllowlist'] ?? null, '$.agentAllowlist');
        $webhookUrlOverride = self::nullableHttpUrlValue($payload['webhookUrlOverride'] ?? null, '$.webhookUrlOverride');
        if (null !== $webhookUrlOverride) {
            self::assertWebhookHostAllowed($webhookUrlOverride, $agentAllowlist, $platformAllowlist);
        }

        return new self(
            self::boolValue($payload['active'] ?? null, false, '$.active'),
            self::ucpVersionValue($payload['ucpVersion'] ?? null),
            $profileUriStrategy,
            $customProfileUri,
            self::enabledCapabilityList($payload),
            self::enabledTransportList($payload),
            self::continueUrlTemplateValue($payload['continueUrlTemplate'] ?? null),
            $platformAllowlist,
            $remoteProfileAllowlist,
            $agentAllowlist,
            self::originList($payload['embeddedAllowedOrigins'] ?? null, '$.embeddedAllowedOrigins'),
            self::frameAncestorList($payload['embeddedFrameAncestors'] ?? null),
            self::intValue($payload['discoveryBudget'] ?? null, 10, '$.discoveryBudget'),
            self::positiveIntValue($payload['catalogResultLimit'] ?? null, 50, '$.catalogResultLimit'),
            $webhookUrlOverride,
            self::signaturePolicyValue($payload['signaturePolicy'] ?? 'strict'),
            self::boolValue($payload['idempotencyRequired'] ?? null, true, '$.idempotencyRequired'),
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
            'catalogResultLimit' => $this->catalogResultLimit,
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

    private static function boolValue(mixed $value, bool $default, string $path): bool
    {
        if (null === $value || '' === $value) {
            return $default;
        }

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value) && \in_array($value, [0, 1], true)) {
            return 1 === $value;
        }

        if (\is_string($value)) {
            $normalized = strtolower(trim($value));
            if (\in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (\in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        throw self::invalid($path, 'must be a boolean');
    }

    private static function intValue(mixed $value, int $default, string $path): int
    {
        if (null === $value || '' === $value) {
            return $default;
        }

        $normalized = filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (false === $normalized) {
            throw self::invalid($path, 'must be a non-negative integer');
        }

        return $normalized;
    }

    private static function positiveIntValue(mixed $value, int $default, string $path): int
    {
        if (null === $value || '' === $value) {
            return $default;
        }

        $normalized = filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $normalized) {
            throw self::invalid($path, 'must be a positive integer');
        }

        return $normalized;
    }

    private static function ucpVersionValue(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return UcpProtocol::VERSION;
        }

        if (!\is_string($value)) {
            throw self::invalid('$.ucpVersion', 'must be a string');
        }

        if (UcpProtocol::VERSION !== $value) {
            throw self::invalid('$.ucpVersion', \sprintf('must be "%s"', UcpProtocol::VERSION));
        }

        return $value;
    }

    private static function profileUriStrategyValue(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return 'domain';
        }

        if (!\is_string($value)) {
            throw self::invalid('$.profileUriStrategy', 'must be a string');
        }

        if (!\in_array($value, self::PROFILE_URI_STRATEGIES, true)) {
            throw self::invalid('$.profileUriStrategy', 'must be one of "domain", "config"');
        }

        return $value;
    }

    private static function signaturePolicyValue(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return 'strict';
        }

        if (!\is_string($value)) {
            throw self::invalid('$.signaturePolicy', 'must be a string');
        }

        if (null === SignaturePolicy::tryFrom($value)) {
            throw self::invalid('$.signaturePolicy', 'must be a supported signature policy');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private static function enabledCapabilityList(array $payload): array
    {
        if (!\array_key_exists('enabledCapabilities', $payload) || !\is_array($payload['enabledCapabilities'])) {
            if (\array_key_exists('enabledCapabilities', $payload) && null !== $payload['enabledCapabilities']) {
                throw self::invalid('$.enabledCapabilities', 'must be a list');
            }

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
            if (\array_key_exists('enabledTransports', $payload) && null !== $payload['enabledTransports']) {
                throw self::invalid('$.enabledTransports', 'must be a list');
            }

            return ['rest'];
        }

        return self::transportList($payload['enabledTransports']);
    }

    /**
     * @return list<string>
     */
    private static function hostList(mixed $value, string $path): array
    {
        return self::normalizedList(
            $value,
            $path,
            static fn (string $entry, string $entryPath): string => self::normalizeHost($entry, $entryPath),
        );
    }

    /**
     * @return list<string>
     */
    private static function originList(mixed $value, string $path): array
    {
        return self::normalizedList(
            $value,
            $path,
            static fn (string $entry, string $entryPath): string => self::normalizeOrigin($entry, $entryPath),
        );
    }

    /**
     * @return list<string>
     */
    private static function frameAncestorList(mixed $value): array
    {
        $ancestors = self::normalizedList(
            $value,
            '$.embeddedFrameAncestors',
            static function (string $entry, string $entryPath): string {
                if (\in_array($entry, ["'self'", "'none'"], true)) {
                    return $entry;
                }

                return self::normalizeOrigin($entry, $entryPath);
            },
        );

        if (\in_array("'none'", $ancestors, true) && \count($ancestors) > 1) {
            throw self::invalid('$.embeddedFrameAncestors', '"none" cannot be combined with other frame ancestors');
        }

        return $ancestors;
    }

    /**
     * @param \Closure(string, string): string $normalize
     *
     * @return list<string>
     */
    private static function normalizedList(mixed $value, string $path, \Closure $normalize): array
    {
        if (null === $value) {
            return [];
        }

        if (!\is_array($value) || false === array_is_list($value)) {
            throw self::invalid($path, 'must be a list');
        }

        $normalized = [];
        foreach ($value as $index => $entry) {
            $entryPath = \sprintf('%s[%d]', $path, $index);
            if (!\is_string($entry)) {
                throw self::invalid($entryPath, 'must be a string');
            }

            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }

            $normalized[] = $normalize($entry, $entryPath);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    private static function capabilityList(mixed $value): array
    {
        $capabilities = [];
        foreach (self::enumList($value, '$.enabledCapabilities') as $capability) {
            if (!\in_array($capability, UcpCapabilityCatalog::allConfigKeys(), true)) {
                throw self::invalid('$.enabledCapabilities', \sprintf('unsupported capability "%s"', $capability));
            }

            $capabilities[] = $capability;
        }

        return array_values(array_unique($capabilities));
    }

    /**
     * @return list<string>
     */
    private static function transportList(mixed $value): array
    {
        $transports = [];
        foreach (self::enumList($value, '$.enabledTransports') as $transport) {
            if (null === Transport::tryFrom($transport)) {
                throw self::invalid('$.enabledTransports', \sprintf('unsupported transport "%s"', $transport));
            }

            $transports[] = $transport;
        }

        return array_values(array_unique($transports));
    }

    /**
     * @return list<string>
     */
    private static function enumList(mixed $value, string $path): array
    {
        if (!\is_array($value) || false === array_is_list($value)) {
            throw self::invalid($path, 'must be a list');
        }

        $entries = [];
        foreach ($value as $index => $entry) {
            if (!\is_string($entry) || '' === trim($entry)) {
                throw self::invalid(\sprintf('%s[%d]', $path, $index), 'must be a non-empty string');
            }

            $entries[] = trim($entry);
        }

        return $entries;
    }

    private static function continueUrlTemplateValue(mixed $value): ?string
    {
        $url = self::nullableHttpUrlValue($value, '$.continueUrlTemplate', true);
        if (null === $url) {
            return null;
        }

        preg_match_all('/\{[^}]+}/', $url, $matches);
        foreach ($matches[0] as $placeholder) {
            if (!\in_array($placeholder, self::CONTINUE_URL_PLACEHOLDERS, true)) {
                throw self::invalid('$.continueUrlTemplate', \sprintf('unsupported placeholder "%s"', $placeholder));
            }
        }

        $withoutKnownPlaceholders = str_replace(self::CONTINUE_URL_PLACEHOLDERS, '', $url);
        if (str_contains($withoutKnownPlaceholders, '{') || str_contains($withoutKnownPlaceholders, '}')) {
            throw self::invalid('$.continueUrlTemplate', 'contains a malformed placeholder');
        }

        return $url;
    }

    private static function nullableHttpUrlValue(mixed $value, string $path, bool $allowTemplate = false): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw self::invalid($path, 'must be a string');
        }

        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        if (!$allowTemplate && (str_contains($value, '{') || str_contains($value, '}'))) {
            throw self::invalid($path, 'must not contain template placeholders');
        }

        return self::normalizeHttpUrl($value, $path);
    }

    private static function normalizeHttpUrl(string $value, string $path): string
    {
        self::assertNoUnsafeUrlCharacters($value, $path);

        $parts = parse_url($value);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw self::invalid($path, 'must be an absolute http(s) URL');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw self::invalid($path, 'must not contain user info');
        }

        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, self::URL_SCHEMES, true)) {
            throw self::invalid($path, 'must use http or https');
        }

        $host = self::normalizeHost($parts['host'], $path);
        $url = $scheme.'://'.self::formatHostForUrl($host);

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        if (isset($parts['path'])) {
            $url .= $parts['path'];
        }

        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }

        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }

    private static function normalizeOrigin(string $value, string $path): string
    {
        self::assertNoUnsafeUrlCharacters($value, $path);

        $parts = parse_url($value);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw self::invalid($path, 'must be an absolute origin');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw self::invalid($path, 'must be an origin without user info, query, or fragment');
        }

        if (isset($parts['path']) && '' !== $parts['path'] && '/' !== $parts['path']) {
            throw self::invalid($path, 'must not contain a path');
        }

        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, self::URL_SCHEMES, true)) {
            throw self::invalid($path, 'must use http or https');
        }

        $host = self::normalizeHost($parts['host'], $path);
        $origin = $scheme.'://'.self::formatHostForUrl($host);

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    private static function normalizeHost(string $value, string $path): string
    {
        $host = strtolower(trim($value));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $host = rtrim($host, '.');
        if ('' === $host || 1 === preg_match('/[\s\x00-\x1F\x7F\/?#@]/', $host)) {
            throw self::invalid($path, 'must be a valid host');
        }

        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return $host;
        }

        if (str_contains($host, ':') || 1 !== preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host)) {
            throw self::invalid($path, 'must be a valid host');
        }

        return $host;
    }

    private static function formatHostForUrl(string $host): string
    {
        return str_contains($host, ':') ? '['.$host.']' : $host;
    }

    private static function assertNoUnsafeUrlCharacters(string $value, string $path): void
    {
        if (1 === preg_match('/[\s\x00-\x1F\x7F]/', $value)) {
            throw self::invalid($path, 'contains unsafe characters');
        }
    }

    /**
     * @param list<string> $agentAllowlist
     * @param list<string> $platformAllowlist
     */
    private static function assertWebhookHostAllowed(string $webhookUrl, array $agentAllowlist, array $platformAllowlist): void
    {
        $host = parse_url($webhookUrl, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            throw self::invalid('$.webhookUrlOverride', 'must include a host');
        }

        $allowedHosts = [] !== $agentAllowlist ? $agentAllowlist : $platformAllowlist;
        if ([] !== $allowedHosts && !\in_array(self::normalizeHost($host, '$.webhookUrlOverride'), $allowedHosts, true)) {
            throw self::invalid('$.webhookUrlOverride', 'host must be listed in agentAllowlist or platformAllowlist');
        }
    }

    private static function invalid(string $path, string $message): UcpConfigException
    {
        return UcpConfigException::invalidValue($path, $message);
    }
}
