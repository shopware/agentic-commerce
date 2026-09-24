<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\TestData\Command;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('framework')]
enum TestDataStatus: string
{
    case Created = 'created';
    case Present = 'already present';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Removed = 'removed';
    case Absent = 'not present';
}
