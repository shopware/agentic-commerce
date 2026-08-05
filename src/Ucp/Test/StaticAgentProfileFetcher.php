<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Test;

use Shopware\Core\Framework\Log\Package;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;

/**
 * Test-only {@see AgentProfileFetcherInterface} that returns a fixed, test-supplied profile
 * instead of fetching one over HTTP.
 *
 * Wired (in the `test` environment only) by {@see \Swag\AgenticCommerce\DependencyInjection\TestAgentProfileFetcherCompilerPass}
 * in place of the SDK's `HttpAgentProfileFetcher`. The functional suite builds the merchant's
 * own capability-bearing {@see PlatformProfile} and injects it via {@see setProfile()}, so the
 * SDK negotiates the full capability set without an HTTP fetch — the real fetcher runs an SSRF
 * URL-safety check that rejects the lane's `*.localhost` host, so the live path can't be
 * exercised from a local lane regardless of scheme.
 *
 * @internal
 */
#[Package('framework')]
final class StaticAgentProfileFetcher implements AgentProfileFetcherInterface
{
    private ?PlatformProfile $profile = null;

    public function setProfile(PlatformProfile $profile): void
    {
        $this->profile = $profile;
    }

    public function fetch(string $uri): PlatformProfile
    {
        if (null === $this->profile) {
            throw new \LogicException(\sprintf('No profile configured on %s. Call setProfile() from the test before issuing a UCP runtime request.', self::class));
        }

        return $this->profile;
    }
}
