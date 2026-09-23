<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Exception;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('framework')]
final class SdkNotAvailableException extends \RuntimeException
{
    public static function bundleCouldNotBeLoaded(): self
    {
        return new self('Unable to load the UCP SDK Symfony bundle from Composer dependencies.');
    }

    /**
     * A cluster setup is the one deployment where nothing will install the SDK later.
     */
    public static function clusterSetupNeedsTheSdkInTheProject(string $reason): self
    {
        return new self(\sprintf(
            'This shop runs with shopware.deployment.cluster_setup enabled, where Shopware never '
            .'runs composer for a plugin, so nothing will install this extension\'s requirements: %s. '
            .'Add them to the project\'s composer.json and deploy, then install the extension.',
            $reason,
        ));
    }
}
