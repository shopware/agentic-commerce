<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\ProfileBuilderInterface;

/** @internal */
final class RecordingProfileBuilder implements ProfileBuilderInterface
{
    public ?ProfileBuildInput $lastInput = null;

    public function build(ProfileBuildInput $input): PlatformProfile
    {
        $this->lastInput = $input;

        return new PlatformProfile(
            $input->version,
            [],
            [],
            [],
            supportedVersions: $input->supportedVersions,
        );
    }
}
