<?php

declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Config\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\Validation\Finding;
use Swag\AgenticCommerce\Ucp\Config\Validation\Severity;
use Swag\AgenticCommerce\Ucp\Config\Validation\UcpConfigValidator;

/**
 * @internal
 */
#[CoversClass(UcpConfigValidator::class)]
class UcpConfigValidatorTest extends TestCase
{
    private const CHANNEL_ID = '0191aaaaaaaa7000aaaaaaaaaaaaaaaa';

    /** @var list<array{url: string}> */
    private const DOMAINS = [['url' => 'https://shop.example.com']];

    public function testAHealthyActiveChannelHasNoFindings(): void
    {
        $findings = $this->validate($this->config(), [$this->activeKey()], self::DOMAINS);

        static::assertSame([], $findings);
    }

    public function testAnInactiveChannelIsAdvisoryOnly(): void
    {
        $findings = $this->validate($this->config(active: false), [], []);

        static::assertCount(1, $findings);
        static::assertSame('inactive', $findings[0]->code);
        static::assertSame(Severity::Info, $findings[0]->severity);
    }

    public function testFindingArrayKeepsPublicJsonShape(): void
    {
        $finding = new Finding(
            self::CHANNEL_ID,
            'Storefront',
            Severity::Warning,
            'signature_policy_log',
            'Signature policy is set to log mode.',
            'Switch the signature policy to strict before production use.',
        );

        static::assertSame([
            'salesChannelId' => self::CHANNEL_ID,
            'salesChannelName' => 'Storefront',
            'severity' => 'warning',
            'code' => 'signature_policy_log',
            'message' => 'Signature policy is set to log mode.',
            'remediation' => 'Switch the signature policy to strict before production use.',
        ], $finding->toArray());
    }

    public function testSignaturePolicyOffIsAnError(): void
    {
        $findings = $this->validate($this->config(signaturePolicy: 'off'), [$this->activeKey()], self::DOMAINS);

        $this->assertFindingSeverity($findings, 'signature_policy_off', Severity::Error);
    }

    public function testSignaturePolicyLogIsAWarning(): void
    {
        $findings = $this->validate($this->config(signaturePolicy: 'log'), [$this->activeKey()], self::DOMAINS);

        $this->assertFindingSeverity($findings, 'signature_policy_log', Severity::Warning);
    }

    public function testNoActiveSigningKeyIsAnError(): void
    {
        $findings = $this->validate($this->config(), [['kid' => 'old', 'status' => 'retired', 'retireAt' => null]], self::DOMAINS);

        $this->assertFindingSeverity($findings, 'no_active_signing_key', Severity::Error);
    }

    public function testAllActiveKeysExpiringSoonIsAWarning(): void
    {
        $now = new \DateTimeImmutable('2026-07-02T00:00:00+00:00');
        $soon = $now->add(new \DateInterval('P5D'))->format(\DATE_ATOM);

        $findings = $this->validate($this->config(), [$this->activeKey($soon)], self::DOMAINS, $now);

        $this->assertFindingSeverity($findings, 'signing_keys_expiring', Severity::Warning);
    }

    public function testAKeyWithoutRetirementDateDoesNotWarn(): void
    {
        $findings = $this->validate($this->config(), [$this->activeKey()], self::DOMAINS);

        static::assertNotContains('signing_keys_expiring', $this->codes($findings));
    }

    public function testNoTransportsIsAnError(): void
    {
        $findings = $this->validate($this->config(transports: []), [$this->activeKey()], self::DOMAINS);

        $this->assertFindingSeverity($findings, 'no_transports', Severity::Error);
    }

    public function testNoCapabilitiesIsAWarning(): void
    {
        $findings = $this->validate($this->config(capabilities: []), [$this->activeKey()], self::DOMAINS);

        $this->assertFindingSeverity($findings, 'no_capabilities', Severity::Warning);
    }

    public function testNoStorefrontDomainIsAnError(): void
    {
        $findings = $this->validate($this->config(), [$this->activeKey()], []);

        $this->assertFindingSeverity($findings, 'no_storefront_domain', Severity::Error);
    }

    public function testProfileDomainNotServedByChannelIsAWarning(): void
    {
        $findings = $this->validate(
            $this->config(profileDomain: 'https://other.example.org'),
            [$this->activeKey()],
            self::DOMAINS,
        );

        $this->assertFindingSeverity($findings, 'profile_domain_unmatched', Severity::Warning);
    }

    public function testIdempotencyDisabledIsAWarning(): void
    {
        $findings = $this->validate($this->config(idempotencyRequired: false), [$this->activeKey()], self::DOMAINS);

        $this->assertFindingSeverity($findings, 'idempotency_not_required', Severity::Warning);
    }

    public function testEmbeddedOriginsWithoutFrameAncestorsIsAWarning(): void
    {
        $findings = $this->validate(
            $this->config(embeddedOrigins: ['https://chatgpt.com'], embeddedAncestors: []),
            [$this->activeKey()],
            self::DOMAINS,
        );

        $this->assertFindingSeverity($findings, 'embedded_frame_ancestors_missing', Severity::Warning);
    }

    public function testEmbeddedFrameAncestorsWithoutOriginsIsAWarning(): void
    {
        $findings = $this->validate(
            $this->config(embeddedOrigins: [], embeddedAncestors: ['https://chatgpt.com']),
            [$this->activeKey()],
            self::DOMAINS,
        );

        $this->assertFindingSeverity($findings, 'embedded_allowed_origins_missing', Severity::Warning);
    }

    /**
     * @param list<array<string, mixed>> $keys
     * @param list<array{url: string}>   $domains
     *
     * @return list<Finding>
     */
    private function validate(UcpConfig $config, array $keys, array $domains, ?\DateTimeImmutable $now = null): array
    {
        return (new UcpConfigValidator())->validate(self::CHANNEL_ID, 'Storefront', $config, $keys, $domains, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function activeKey(?string $retireAt = null): array
    {
        return ['kid' => 'k1', 'status' => 'active', 'retireAt' => $retireAt];
    }

    /**
     * @param list<string> $capabilities
     * @param list<string> $transports
     * @param list<string> $embeddedOrigins
     * @param list<string> $embeddedAncestors
     */
    private function config(
        bool $active = true,
        string $signaturePolicy = 'strict',
        bool $idempotencyRequired = true,
        ?string $profileDomain = 'https://shop.example.com',
        array $capabilities = ['catalog'],
        array $transports = ['rest'],
        array $embeddedOrigins = [],
        array $embeddedAncestors = [],
    ): UcpConfig {
        return new UcpConfig(
            active: $active,
            profileDomain: $profileDomain,
            enabledCapabilities: $capabilities,
            enabledTransports: $transports,
            embeddedAllowedOrigins: $embeddedOrigins,
            embeddedFrameAncestors: $embeddedAncestors,
            signaturePolicy: $signaturePolicy,
            idempotencyRequired: $idempotencyRequired,
        );
    }

    /**
     * @param list<Finding> $findings
     */
    private function assertFindingSeverity(array $findings, string $code, Severity $expected): void
    {
        foreach ($findings as $finding) {
            if ($finding->code === $code) {
                static::assertSame($expected, $finding->severity, "Finding {$code} has unexpected severity");
                static::assertNotSame('', $finding->message);

                return;
            }
        }

        static::fail(\sprintf('Expected a finding with code "%s"; got: %s', $code, implode(', ', $this->codes($findings))));
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private function codes(array $findings): array
    {
        return array_map(static fn (Finding $finding): string => $finding->code, $findings);
    }
}
