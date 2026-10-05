<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\Ucp\Admin\Fixtures;

use Swag\AgenticCommerce\Ucp\Config\UcpActivationWriterInterface;

/** @internal */
final class RecordingUcpActivationWriter implements UcpActivationWriterInterface
{
    /** @var list<string> */
    public array $activated = [];

    public function activate(string $salesChannelId): void
    {
        $this->activated[] = $salesChannelId;
    }
}
