<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Profile;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;

/**
 * The config a profile preview renders. ProfilePreviewBuilder sets it for the duration of one build,
 * so CapabilityFilteringProfileContributor filters with the merchant's unsaved form instead of
 * reloading the stored config of the channel.
 *
 * @internal
 */
#[Package('checkout')]
final class ProfileDraftConfig
{
    private ?UcpConfig $config = null;

    public function current(): ?UcpConfig
    {
        return $this->config;
    }

    /**
     * @template T
     *
     * @param callable(): T $build
     *
     * @return T
     */
    public function during(UcpConfig $config, callable $build): mixed
    {
        $previous = $this->config;
        $this->config = $config;

        try {
            return $build();
        } finally {
            $this->config = $previous;
        }
    }
}
