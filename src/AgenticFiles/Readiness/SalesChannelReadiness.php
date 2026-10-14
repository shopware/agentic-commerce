<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\AgenticFiles\Readiness;

use Shopware\Core\Framework\Log\Package;

/** @internal */
#[Package('discovery')]
final class SalesChannelReadiness implements \JsonSerializable
{
    /**
     * @param array<string, ReadinessFileState> $files keyed by file name
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly array $files,
    ) {
    }

    /**
     * @return array{id: string, name: string|null, files: list<array{fileName: string, state: string}>}
     */
    public function jsonSerialize(): array
    {
        $files = [];
        foreach ($this->files as $fileName => $state) {
            $files[] = ['fileName' => $fileName, 'state' => $state->value];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'files' => $files,
        ];
    }
}
