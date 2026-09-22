<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce;

use Composer\InstalledVersions;
use Shopware\Core\Framework\Log\Package;

/**
 * Answers whether the shop has the UCP SDK this plugin was built against.
 *
 * A zip install extracts the plugin one request before Shopware runs `composer require`, so an
 * active plugin boots at least once with its dependency missing -- or, when the pinned SDK moves,
 * with the previous one still installed. Both are asked about here rather than with
 * `class_exists`, because a present but older SDK loads and then fails on the first class the new
 * code needs.
 *
 * Nothing in this class may touch the SDK: it runs while the container is being compiled, which is
 * exactly when the SDK cannot be assumed.
 *
 * @internal
 */
#[Package('framework')]
final class SdkAvailability
{
    public const BUNDLE_CLASS = 'Ucp\\Sdk\\Symfony\\UcpSdkBundle';

    private const PACKAGES = ['ucp-php-sdk/core', 'ucp-php-sdk/symfony-bundle'];

    private const LOG_FILE = 'swag-agentic-commerce.log';

    /** @var array<string, string|null> */
    private static array $reasons = [];

    public static function isUsable(string $pluginRoot): bool
    {
        return null === self::reason($pluginRoot);
    }

    /**
     * @return string|null null when the SDK is usable, otherwise what is wrong with it
     */
    public static function reason(string $pluginRoot): ?string
    {
        return self::$reasons[$pluginRoot] ??= self::determine($pluginRoot);
    }

    /**
     * Records why the plugin switched itself off, and what to type to switch it back on.
     *
     * Written to the shop's own log directory, because the alternative is a merchant seeing
     * product feeds and UCP endpoints disappear with nothing anywhere saying why.
     */
    public static function logUnusable(string $pluginRoot, ?string $logDir): void
    {
        $reason = self::reason($pluginRoot);

        if (null === $reason) {
            return;
        }

        $message = \sprintf(
            '[SwagAgenticCommerce] The extension is inactive: %s. Shopware installs this itself '
            .'when the plugin is installed or updated; if that did not happen -- an offline shop, '
            .'or an interrupted update -- run `composer require %s` in the shop root and clear the '
            .'cache. The extension registers no services, routes or feeds until then.',
            $reason,
            implode(' ', array_map(
                static fn (string $package, string $constraint): string => $package.':'.$constraint,
                array_keys(self::requirements($pluginRoot)),
                array_values(self::requirements($pluginRoot)),
            )),
        );

        error_log($message);

        if (null === $logDir || !is_dir($logDir) || !is_writable($logDir)) {
            return;
        }

        @file_put_contents(
            rtrim($logDir, '/').'/'.self::LOG_FILE,
            \sprintf('[%s] %s%s', date('c'), $message, \PHP_EOL),
            \FILE_APPEND,
        );
    }

    private static function determine(string $pluginRoot): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return 'Composer\'s runtime registry is unavailable, so the SDK cannot be located';
        }

        foreach (self::requirements($pluginRoot) as $package => $constraint) {
            if (!InstalledVersions::isInstalled($package)) {
                return \sprintf('%s %s is required but not installed', $package, $constraint);
            }

            $installed = (string) InstalledVersions::getPrettyVersion($package);

            // The constraint is an exact version on purpose, which makes this a comparison rather
            // than a resolver. Anything looser is left to Composer to have judged already.
            if (1 === preg_match('/^\d+\.\d+\.\d+$/', $constraint) && $installed !== $constraint) {
                return \sprintf('%s %s is installed, but this version needs %s', $package, $installed, $constraint);
            }
        }

        if (!class_exists(self::BUNDLE_CLASS)) {
            return \sprintf('%s is installed but its classes cannot be loaded', self::PACKAGES[1]);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function requirements(string $pluginRoot): array
    {
        $manifest = $pluginRoot.'/composer.json';
        $contents = is_file($manifest) ? file_get_contents($manifest) : false;

        if (false === $contents) {
            return array_fill_keys(self::PACKAGES, '*');
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return array_fill_keys(self::PACKAGES, '*');
        }

        $require = \is_array($decoded['require'] ?? null) ? $decoded['require'] : [];
        $requirements = [];

        foreach (self::PACKAGES as $package) {
            $constraint = $require[$package] ?? '*';
            $requirements[$package] = \is_string($constraint) ? $constraint : '*';
        }

        return $requirements;
    }
}
