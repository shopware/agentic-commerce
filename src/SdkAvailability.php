<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce;

use Composer\InstalledVersions;
use Shopware\Core\Framework\Log\Package;

/**
 * The bundled SDK wins over a root `vendor/` copy through `BundledClassLoading`. A root copy that still serves
 * the classes, for a plugin installed in `vendor/`, must have the bundled version: PSR-4 falls back per class and
 * would otherwise mix the two versions.
 *
 * Must not load any SDK class beyond the two probes; it runs while the container is compiled.
 *
 * @internal
 */
#[Package('framework')]
final class SdkAvailability
{
    public const BUNDLE_CLASS = 'Ucp\\Sdk\\Symfony\\UcpSdkBundle';

    private const PACKAGES = ['ucp-php-sdk/core', 'ucp-php-sdk/symfony-bundle'];

    private const PROBE_CLASS_BY_PACKAGE = [
        'ucp-php-sdk/core' => 'Ucp\\Sdk\\Enum\\Transport',
        'ucp-php-sdk/symfony-bundle' => self::BUNDLE_CLASS,
    ];

    private const LOG_FILE = 'swag-agentic-commerce.log';

    /** @var array<string, array{reason: string|null, isBundled: bool}> */
    private static array $verdictByPluginRoot = [];

    public static function isUsable(string $pluginRoot): bool
    {
        return null === self::reason($pluginRoot);
    }

    /**
     * @return string|null null when the SDK is usable, otherwise what is wrong with it
     */
    public static function reason(string $pluginRoot): ?string
    {
        return self::verdict($pluginRoot)['reason'];
    }

    public static function isBundled(string $pluginRoot): bool
    {
        return self::verdict($pluginRoot)['isBundled'];
    }

    /**
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
            '[SwagAgenticCommerce] The extension is inactive: %s. It registers no services, routes '
            .'or feeds until that is fixed. ',
            $reason,
        );

        if (false === realpath($pluginRoot.'/'.BundledClassLoading::BUNDLED_SDK_DIRECTORY)) {
            $message .= \sprintf(
                'Run `composer require %s` in the shop root and clear the cache.',
                self::requireArguments($pluginRoot),
            );
        } else {
            $message .= 'The release archive bundles the SDK, so Shopware installs no requirements '
                .'for it. Clear the cache once the conflict is resolved.';
        }

        self::writeLog($message, $logDir);
    }

    private static function writeLog(string $message, ?string $logDir): void
    {
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

    /**
     * @return array{reason: string|null, isBundled: bool}
     */
    private static function verdict(string $pluginRoot): array
    {
        return self::$verdictByPluginRoot[$pluginRoot] ??= self::determine($pluginRoot);
    }

    /**
     * @return array{reason: string|null, isBundled: bool}
     */
    private static function determine(string $pluginRoot): array
    {
        if (!class_exists(InstalledVersions::class)) {
            return self::unusable('Composer\'s runtime registry is unavailable, so the SDK cannot be located');
        }

        $classFileByPackage = [];

        foreach (self::PROBE_CLASS_BY_PACKAGE as $package => $probeClass) {
            $classFile = class_exists($probeClass) ? (new \ReflectionClass($probeClass))->getFileName() : false;
            $classFileByPackage[$package] = false === $classFile ? null : $classFile;
        }

        return self::evaluate(
            $pluginRoot,
            $classFileByPackage,
            static fn (string $package): ?array => InstalledVersions::isInstalled($package)
                ? ['prettyVersion' => InstalledVersions::getPrettyVersion($package)]
                : null,
        );
    }

    /**
     * @param array<string, string|null>                                 $classFileByPackage
     * @param \Closure(string): (array{prettyVersion: string|null}|null) $installedPackage   null when Composer does not know the package; a null version means another package replaces it
     *
     * @return array{reason: string|null, isBundled: bool}
     */
    private static function evaluate(string $pluginRoot, array $classFileByPackage, \Closure $installedPackage): array
    {
        $manifest = BundledClassLoading::readManifest($pluginRoot);
        $bundledSdkRoot = realpath($pluginRoot.'/'.BundledClassLoading::BUNDLED_SDK_DIRECTORY);

        $missingExtension = self::missingExtension($pluginRoot, $manifest, false !== $bundledSdkRoot);

        if (null !== $missingExtension) {
            return self::unusable(\sprintf('The PHP extension %s is required by the UCP SDK but not loaded', $missingExtension));
        }

        if (false === $bundledSdkRoot) {
            return self::evaluateDevelopmentCheckout($manifest, $classFileByPackage, $installedPackage);
        }

        $isServedFromBundleByPackage = [];

        foreach (self::PACKAGES as $package) {
            $classFile = $classFileByPackage[$package] ?? null;

            if (null === $classFile) {
                return self::unusable(\sprintf('The classes of %s cannot be loaded', $package));
            }

            $isServedFromBundleByPackage[$package] = str_starts_with($classFile, $bundledSdkRoot.'/');
        }

        $isEveryPackageBundled = !\in_array(false, $isServedFromBundleByPackage, true);

        if (!$isEveryPackageBundled && \in_array(true, $isServedFromBundleByPackage, true)) {
            return self::unusable(\sprintf(
                'The SDK packages are loaded from different places: %s. %s',
                implode(', ', array_map(
                    static fn (string $package, bool $isServedFromBundle): string => \sprintf('%s from %s', $package, $isServedFromBundle ? 'the bundled copy' : 'the shop\'s root vendor/'),
                    array_keys($isServedFromBundleByPackage),
                    array_values($isServedFromBundleByPackage),
                )),
                self::rootCopyFix($manifest),
            ));
        }

        if ($isEveryPackageBundled) {
            return ['reason' => null, 'isBundled' => true];
        }

        $bundledVersionByPackage = self::bundledVersionByPackage($manifest);

        foreach (self::PACKAGES as $package) {
            $composerPackage = $installedPackage($package);
            $bundledVersion = $bundledVersionByPackage[$package] ?? null;

            if (null === $composerPackage) {
                return self::unusable(\sprintf('%s is loaded from outside the plugin, but Composer does not know it. %s', $package, self::rootCopyFix($manifest)));
            }
            if (null !== $composerPackage['prettyVersion'] && $composerPackage['prettyVersion'] !== $bundledVersion) {
                return self::unusable(\sprintf(
                    '%s %s is installed in the shop\'s root vendor/, but this version bundles %s. %s',
                    $package,
                    $composerPackage['prettyVersion'],
                    $bundledVersion ?? 'no version',
                    self::rootCopyFix($manifest),
                ));
            }
        }

        return ['reason' => null, 'isBundled' => false];
    }

    /**
     * @param array<string, mixed>                                       $manifest
     * @param array<string, string|null>                                 $classFileByPackage
     * @param \Closure(string): (array{prettyVersion: string|null}|null) $installedPackage
     *
     * @return array{reason: string|null, isBundled: bool}
     */
    private static function evaluateDevelopmentCheckout(array $manifest, array $classFileByPackage, \Closure $installedPackage): array
    {
        foreach (self::constraintByPackage($manifest) as $package => $constraint) {
            $composerPackage = $installedPackage($package);

            if (null === $composerPackage) {
                return self::unusable(\sprintf('%s %s is required but not installed', $package, $constraint));
            }

            $installedVersion = (string) $composerPackage['prettyVersion'];

            // The constraint is an exact version on purpose, which makes this a comparison rather
            // than a resolver. Anything looser is left to Composer to have judged already.
            if (1 === preg_match('/^\d+\.\d+\.\d+$/', $constraint) && $installedVersion !== $constraint) {
                return self::unusable(\sprintf('%s %s is installed, but this version needs %s', $package, $installedVersion, $constraint));
            }
        }

        if (null === ($classFileByPackage[self::PACKAGES[1]] ?? null)) {
            return self::unusable(\sprintf('%s is installed but its classes cannot be loaded', self::PACKAGES[1]));
        }

        return ['reason' => null, 'isBundled' => false];
    }

    /**
     * @return array{reason: string, isBundled: false}
     */
    private static function unusable(string $reason): array
    {
        return ['reason' => $reason, 'isBundled' => false];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function rootCopyFix(array $manifest): string
    {
        $bundledVersionByPackage = self::bundledVersionByPackage($manifest);

        return \sprintf(
            'Run `composer remove %s` in the shop root, or require %s there',
            implode(' ', self::PACKAGES),
            implode(' ', array_map(
                static fn (string $package): string => $package.':'.($bundledVersionByPackage[$package] ?? '*'),
                self::PACKAGES,
            )),
        );
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function missingExtension(string $pluginRoot, array $manifest, bool $hasBundledSdk): ?string
    {
        $requirementSources = [$manifest];

        if ($hasBundledSdk) {
            foreach (self::PACKAGES as $package) {
                $requirementSources[] = BundledClassLoading::readManifest(\sprintf(
                    '%s/%s/%s',
                    $pluginRoot,
                    BundledClassLoading::BUNDLED_SDK_DIRECTORY,
                    substr($package, \strlen('ucp-php-sdk/')),
                ));
            }
        }

        foreach ($requirementSources as $requirementSource) {
            $constraintByRequiredPackage = \is_array($requirementSource['require'] ?? null) ? $requirementSource['require'] : [];

            foreach (array_keys($constraintByRequiredPackage) as $requirement) {
                if (str_starts_with((string) $requirement, 'ext-') && !\extension_loaded(substr((string) $requirement, 4))) {
                    return (string) $requirement;
                }
            }
        }

        return null;
    }

    private static function requireArguments(string $pluginRoot): string
    {
        $constraintByPackage = self::constraintByPackage(BundledClassLoading::readManifest($pluginRoot));

        return implode(' ', array_map(
            static fn (string $package, string $constraint): string => $package.':'.$constraint,
            array_keys($constraintByPackage),
            array_values($constraintByPackage),
        ));
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<string, string>
     */
    private static function constraintByPackage(array $manifest): array
    {
        $constraintByRequiredPackage = \is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
        $constraintByPackage = [];

        foreach (self::PACKAGES as $package) {
            $constraint = $constraintByRequiredPackage[$package] ?? '*';
            $constraintByPackage[$package] = \is_string($constraint) ? $constraint : '*';
        }

        return $constraintByPackage;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array<string, string>
     */
    private static function bundledVersionByPackage(array $manifest): array
    {
        $replacedVersionByPackage = \is_array($manifest['replace'] ?? null) ? $manifest['replace'] : [];
        $bundledVersionByPackage = [];

        foreach (self::PACKAGES as $package) {
            if (\is_string($replacedVersionByPackage[$package] ?? null)) {
                $bundledVersionByPackage[$package] = $replacedVersionByPackage[$package];
            }
        }

        return $bundledVersionByPackage;
    }
}
