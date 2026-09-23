<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyException;
use Swag\AgenticCommerce\Ucp\Admin\SigningKey\UcpSigningKeyService;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Config\Validation\Finding;
use Swag\AgenticCommerce\Ucp\Config\Validation\Severity;
use Swag\AgenticCommerce\Ucp\Config\Validation\UcpConfigValidator;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelDomainView;
use Swag\AgenticCommerce\Ucp\SalesChannel\SalesChannelView;

/**
 * Gathers the inputs {@see UcpConfigValidator} needs and runs it for one channel.
 *
 * The validator is a pure function of config, signing keys and domains; the
 * gathering is the part that touches services, and both the bulk activation and
 * the readiness page need exactly the same answer. Keeping it here means a
 * channel reports the same findings whichever surface asked.
 *
 * @internal
 */
#[Package('framework')]
final class ChannelFindingsResolver
{
    public function __construct(
        private readonly UcpConfigValidator $validator,
        private readonly UcpSigningKeyService $signingKeyService,
    ) {
    }

    /**
     * @return list<Finding>
     */
    public function resolve(string $salesChannelId, ?string $name, UcpConfig $config, ?SalesChannelView $view): array
    {
        try {
            $signingKeys = $this->signingKeyService->all($salesChannelId);
        } catch (UcpSigningKeyException) {
            // A key that cannot be read costs this channel its readiness report,
            // never the caller's operation.
            $signingKeys = [];
        }

        $domains = null === $view ? [] : array_map(
            static fn (SalesChannelDomainView $domain): array => $domain->jsonSerialize(),
            $view->domains,
        );

        return $this->validator->validate($salesChannelId, $name ?? '', $config, $signingKeys, $domains);
    }

    /**
     * @param list<Finding> $findings
     */
    public function hasError(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (Severity::Error === $finding->severity) {
                return true;
            }
        }

        return false;
    }
}
