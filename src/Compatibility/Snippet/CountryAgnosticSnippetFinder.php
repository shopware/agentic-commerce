<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Compatibility\Snippet;

use Shopware\Administration\Snippet\SnippetFinderInterface;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\Compatibility\ShopwareVersionDetector;
use Symfony\Component\Finder\Finder;

/**
 * Compat loader for country-agnostic admin snippet filenames: before 6.7.3.0
 * the core SnippetFinder only matches full-locale filenames (de-DE.json), so
 * the plugin's de.json/en.json would never be served. This decorator merges
 * them in on those versions and stays passive from 6.7.3.0 on, where core
 * loads them itself (and remote translations may refine them).
 *
 * Must decorate with a negative priority so it wraps CachedSnippetFinder:
 * the cache decorator typehints the concrete SnippetFinder and rejects this
 * class as its inner service. The core result stays cached; only the
 * plugin-file merge (a no-op from 6.7.3.0 on) runs per request.
 *
 * @internal
 */
#[Package('framework')]
final class CountryAgnosticSnippetFinder implements SnippetFinderInterface
{
    public function __construct(
        private readonly SnippetFinderInterface $inner,
        private readonly ShopwareVersionDetector $versionDetector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function findSnippets(string $locale): array
    {
        $snippets = $this->inner->findSnippets($locale);

        if (!$this->versionDetector->needsCountryAgnosticSnippetCompat()) {
            return $snippets;
        }

        foreach ($this->loadPluginSnippets($locale) as $pluginSnippets) {
            $snippets = array_replace_recursive($snippets, $pluginSnippets);
        }

        return $snippets;
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function loadPluginSnippets(string $locale): iterable
    {
        $language = explode('-', $locale)[0];
        $path = __DIR__.'/../../Resources/app/administration/src';

        if ('' === $language || !is_dir($path)) {
            return;
        }

        $files = (new Finder())
            ->files()
            ->exclude('node_modules')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            ->ignoreUnreadableDirs()
            ->path('snippet')
            ->name(\sprintf('%s.json', $language))
            ->in($path);

        foreach ($files as $file) {
            $decoded = json_decode($file->getContents(), true, 512, \JSON_THROW_ON_ERROR);

            if (\is_array($decoded)) {
                yield $decoded;
            }
        }
    }
}
