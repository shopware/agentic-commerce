<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit;

use Ucp\Sdk\Model\Profile\PaymentHandlerDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Service\ProfileBuilderInterface;

/** @internal */
final class RecordingProfileBuilder implements ProfileBuilderInterface
{
    public ?ProfileBuildInput $lastInput = null;

    /**
     * @param array<string, list<PaymentHandlerDescriptor>> $paymentHandlers
     */
    public function __construct(private readonly array $paymentHandlers = [])
    {
    }

    public static function advertising(string ...$handlerIds): self
    {
        $descriptorsByHandlerId = [];
        foreach ($handlerIds as $handlerId) {
            $descriptorsByHandlerId[$handlerId] = [new PaymentHandlerDescriptor(
                $handlerId,
                $handlerId,
                '2026-08-25',
                'https://example.com/spec',
                'https://example.com/config.json',
                ['https://example.com/instrument.json'],
            )];
        }

        return new self($descriptorsByHandlerId);
    }

    public function build(ProfileBuildInput $input): PlatformProfile
    {
        $this->lastInput = $input;

        return new PlatformProfile(
            $input->version,
            [],
            [],
            $this->paymentHandlers,
            supportedVersions: $input->supportedVersions,
        );
    }
}
