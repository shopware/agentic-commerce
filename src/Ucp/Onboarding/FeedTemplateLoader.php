<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Ucp\Onboarding;

use Shopware\Core\Framework\Log\Package;

/**
 * Reads the product-export templates a created feed channel starts with.
 *
 * The files are copies of the Admin's `agentic-product-export-templates/*.twig.js`
 * literals and must stay byte-identical to them: the JSONL body is newline-sensitive.
 *
 * @internal
 */
#[Package('framework')]
final class FeedTemplateLoader
{
    private const DEFAULT_DIRECTORY = __DIR__.'/../../Resources/product-export-templates';

    public function __construct(
        private readonly string $directory = self::DEFAULT_DIRECTORY,
    ) {
    }

    /**
     * @return array{headerTemplate: string, bodyTemplate: string, footerTemplate: string}
     */
    public function load(FeedProvider $provider): array
    {
        $extension = FeedProvider::OpenAi === $provider ? 'json' : 'xml';

        return [
            'headerTemplate' => $this->read($provider, 'header', $extension),
            'bodyTemplate' => $this->read($provider, 'body', $extension),
            'footerTemplate' => $this->read($provider, 'footer', $extension),
        ];
    }

    private function read(FeedProvider $provider, string $part, string $extension): string
    {
        $path = \sprintf('%s/%s/%s.%s.twig', $this->directory, $provider->templateDirectory(), $part, $extension);
        if (!is_file($path)) {
            return '';
        }

        $content = file_get_contents($path);

        return false === $content ? '' : $content;
    }
}
