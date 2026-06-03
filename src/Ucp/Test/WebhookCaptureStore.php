<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Test;

use Shopware\Core\Framework\Log\Package;

/**
 * @internal
 */
#[Package('framework')]
final readonly class WebhookCaptureStore
{
    public function __construct(
        private string $projectDir,
    ) {
    }

    public function clear(): void
    {
        if (is_file($this->capturePath())) {
            unlink($this->capturePath());
        }
    }

    /**
     * @param array<string, mixed> $capture
     */
    public function save(array $capture): void
    {
        $directory = \dirname($this->capturePath());
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        file_put_contents($this->capturePath(), json_encode($capture, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(): ?array
    {
        if (!is_file($this->capturePath())) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->capturePath()), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : null;
    }

    private function capturePath(): string
    {
        return $this->projectDir.'/var/swag-agentic-commerce/webhook-capture.json';
    }
}
