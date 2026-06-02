<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Swag\AgenticCommerce\Ucp\Config\UcpConfig;
use Swag\AgenticCommerce\Ucp\Profile\ProfilePreviewBuilder;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\ProfileBuilderInterface;

/** @internal */
final class ProfilePreviewBuilderTest extends TestCase
{
    #[Test]
    public function testItDoesNotAdvertiseCurrentVersionAsSupportedVersion(): void
    {
        $profileBuilder = new RecordingProfileBuilder();
        $previewBuilder = new ProfilePreviewBuilder(
            $profileBuilder,
            new ShopwareVersionDetector('6.6.0.0'),
        );

        $preview = $previewBuilder->build(UcpConfig::fromArray(['active' => true]), 'https://merchant.example');

        self::assertSame([], $profileBuilder->lastInput?->supportedVersions);
        self::assertArrayNotHasKey('supported_versions', $preview['ucp']);
    }
}

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
