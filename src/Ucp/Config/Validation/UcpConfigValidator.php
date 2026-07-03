<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Config\Validation;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;

/**
 * Health checks for a sales channel's UCP configuration.
 *
 * This is deliberately a pure function of its inputs — the config, the channel's
 * signing keys and its storefront domains — so it is trivially testable and has
 * no I/O. The command layer gathers those inputs and renders the findings.
 *
 * It complements (does not duplicate) the schema validation in
 * {@see UcpConfig::fromArray()}: that rejects malformed values at save time,
 * while this surfaces the *state and cross-field readiness* problems save cannot
 * see — weak-but-valid settings, missing keys, "exposed but nothing works", and
 * unresolvable discovery domains. Findings for an inactive channel are advisory
 * (INFO) because nothing is exposed yet.
 *
 * @internal
 */
#[Package('framework')]
class UcpConfigValidator
{
    public const KEY_EXPIRY_WARNING_DAYS = 14;

    /**
     * @param list<array<string, mixed>> $signingKeys keys as returned by UcpSigningKeyService::all()
     * @param array<mixed>               $domains     the channel's storefront domains ([['url' => ...], ...])
     *
     * @return list<Finding>
     */
    public function validate(
        string $salesChannelId,
        string $channelName,
        UcpConfig $config,
        array $signingKeys,
        array $domains,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable();

        /** @var list<Finding> $findings */
        $findings = [];
        $add = static function (Severity $severity, string $code, string $message, ?string $remediation = null) use (&$findings, $salesChannelId, $channelName): void {
            $findings[] = new Finding($salesChannelId, $channelName, $severity, $code, $message, $remediation);
        };

        // Nothing is exposed while UCP is off, so its config problems are advisory only.
        if (!$config->active) {
            $add(
                Severity::Info,
                'inactive',
                'UCP is off on this sales channel; enable it in the Administration (Agentic Commerce > Exposure) to serve agents.',
            );

            return $findings;
        }

        $this->checkSignaturePolicy($config, $salesChannelId, $add);
        $this->checkSigningKeys($signingKeys, $salesChannelId, $now, $add);
        $this->checkExposureReadiness($config, $add);
        $this->checkProfileDomain($config, $domains, $add);
        $this->checkIdempotency($config, $salesChannelId, $add);
        $this->checkEmbedded($config, $salesChannelId, $add);

        return $findings;
    }

    private function checkSignaturePolicy(UcpConfig $config, string $salesChannelId, callable $add): void
    {
        if ('off' === $config->signaturePolicy) {
            $add(
                Severity::Error,
                'signature_policy_off',
                'Signature verification is disabled (signaturePolicy=off) while UCP is exposed — inbound requests are neither verified nor logged.',
                \sprintf('bin/console ucp:config:set --sales-channel=%s --signature-policy=strict', $salesChannelId),
            );
        } elseif ('log' === $config->signaturePolicy) {
            $add(
                Severity::Warning,
                'signature_policy_log',
                'Signatures are logged but not enforced (signaturePolicy=log); invalid signatures are accepted. Use strict once integrations are verified.',
                \sprintf('bin/console ucp:config:set --sales-channel=%s --signature-policy=strict', $salesChannelId),
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $signingKeys
     */
    private function checkSigningKeys(array $signingKeys, string $salesChannelId, \DateTimeImmutable $now, callable $add): void
    {
        $activeKeys = array_values(array_filter(
            $signingKeys,
            static fn (array $key): bool => 'active' === ($key['status'] ?? null),
        ));

        if ([] === $activeKeys) {
            $add(
                Severity::Error,
                'no_active_signing_key',
                'No active signing key for this channel — discovery cannot publish a public key and signed requests will fail.',
                \sprintf('bin/console ucp:signing-keys:generate --sales-channel=%s', $salesChannelId),
            );

            return;
        }

        $threshold = $now->add(new \DateInterval('P'.self::KEY_EXPIRY_WARNING_DAYS.'D'));
        foreach ($activeKeys as $key) {
            $retireAt = $key['retireAt'] ?? null;
            if (!\is_string($retireAt) || '' === $retireAt) {
                return; // A key without a retirement date keeps the channel covered.
            }

            try {
                $retiresAt = new \DateTimeImmutable($retireAt);
            } catch (\Exception) {
                return; // Unparseable date — do not raise a false rotation warning.
            }

            if ($retiresAt > $threshold) {
                return; // At least one active key survives the warning window.
            }
        }

        $add(
            Severity::Warning,
            'signing_keys_expiring',
            \sprintf('All active signing keys retire within %d days; generate a replacement before they lapse.', self::KEY_EXPIRY_WARNING_DAYS),
            \sprintf('bin/console ucp:signing-keys:generate --sales-channel=%s', $salesChannelId),
        );
    }

    private function checkExposureReadiness(UcpConfig $config, callable $add): void
    {
        if ([] === $config->enabledTransports) {
            $add(
                Severity::Error,
                'no_transports',
                'UCP is exposed but no transports are enabled, so agents have no way to reach it.',
                'Enable at least one transport in the Administration (Agentic Commerce > Exposure).',
            );
        }

        if ([] === $config->enabledCapabilities) {
            $add(
                Severity::Warning,
                'no_capabilities',
                'UCP is exposed but no capabilities are enabled, so it advertises nothing to transact.',
                'Enable capabilities in the Administration (Agentic Commerce > Exposure).',
            );
        }
    }

    /**
     * @param array<mixed> $domains
     */
    private function checkProfileDomain(UcpConfig $config, array $domains, callable $add): void
    {
        if ([] === $domains) {
            $add(
                Severity::Error,
                'no_storefront_domain',
                'The sales channel has no storefront domain, so /.well-known/ucp cannot build a canonical profile URL.',
                'Add a domain to the sales channel in the Administration.',
            );

            return;
        }

        $profileDomain = $config->profileDomain;
        if (null === $profileDomain || '' === $profileDomain) {
            return;
        }

        $wantedHost = parse_url($profileDomain, \PHP_URL_HOST);
        foreach ($domains as $domain) {
            if (!\is_array($domain)) {
                continue;
            }

            $url = $domain['url'] ?? null;
            if (\is_string($url) && parse_url($url, \PHP_URL_HOST) === $wantedHost) {
                return;
            }
        }

        $add(
            Severity::Warning,
            'profile_domain_unmatched',
            \sprintf('profileDomain "%s" is not one of this channel\'s storefront domains; discovery may advertise an unreachable profile URL.', $profileDomain),
            'Set profileDomain to one of the channel\'s domains in the Administration, or clear it to use the default.',
        );
    }

    private function checkIdempotency(UcpConfig $config, string $salesChannelId, callable $add): void
    {
        if (!$config->idempotencyRequired) {
            $add(
                Severity::Warning,
                'idempotency_not_required',
                'Idempotency keys are not required on write requests, so a retried agent call can double-submit (e.g. place two orders).',
                \sprintf('bin/console ucp:config:set --sales-channel=%s --idempotency=true', $salesChannelId),
            );
        }
    }

    private function checkEmbedded(UcpConfig $config, string $salesChannelId, callable $add): void
    {
        $hasOrigins = [] !== $config->embeddedAllowedOrigins;
        $hasAncestors = [] !== $config->embeddedFrameAncestors;

        if ($hasOrigins === $hasAncestors) {
            return; // Both set or both empty — consistent.
        }

        if ($hasOrigins) {
            $add(
                Severity::Warning,
                'embedded_frame_ancestors_missing',
                'embeddedAllowedOrigins is set but embeddedFrameAncestors is empty; the embedded page will be blocked from framing by CSP.',
                \sprintf('bin/console ucp:config:set --sales-channel=%s --embedded-frame-ancestors=<origin>', $salesChannelId),
            );

            return;
        }

        $add(
            Severity::Warning,
            'embedded_allowed_origins_missing',
            'embeddedFrameAncestors is set but embeddedAllowedOrigins is empty; embedded pages will return a controlled error for cross-origin requests.',
            \sprintf('bin/console ucp:config:set --sales-channel=%s --embedded-allowed-origins=<origin>', $salesChannelId),
        );
    }
}
