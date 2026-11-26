<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\UpdateProtection;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\BundledClassLoading;

/**
 * Loads every class of the plugin, and of the bundled SDK, before core replaces their files.
 *
 * PHP cannot unload a class, so a class that is loaded after the swap comes from the new files and
 * meets the old ones already in memory.
 *
 * @internal
 */
#[Package('framework')]
final class ClassPreloader
{
    private const MISSING_CLASS_MESSAGE = '/^(Class|Interface|Trait) ".+" not found$/';

    private static bool $hasLoadedEveryClass = false;

    public function __construct(
        private readonly string $pluginRoot,
        private readonly string $markerPath,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function hasLoadedEveryClass(): bool
    {
        return self::$hasLoadedEveryClass;
    }

    /**
     * A marker file left behind means the previous preload died on a method signature that does not
     * match its core parent, which no `catch` sees. The next call skips once instead of blocking
     * every later update the same way.
     *
     * @return array<string, string> the error message by class name, for each class that failed to load
     */
    public function preload(bool $includesSdkClasses): array
    {
        self::$hasLoadedEveryClass = false;

        $classLists = BundledClassLoading::readClassLists($this->pluginRoot);

        if (null === $classLists) {
            return [];
        }

        if (is_file($this->markerPath)) {
            @unlink($this->markerPath);
            $this->logger?->warning('A previous preload of the plugin classes did not finish, so this one is skipped.');

            return [];
        }

        $classNames = $includesSdkClasses ? array_merge($classLists['plugin'], $classLists['sdk']) : $classLists['plugin'];

        $this->writeMarker();
        $startedAt = hrtime(true);
        $errorMessageByClassName = [];

        try {
            foreach ($classNames as $className) {
                try {
                    // Autoloading declares an interface, a trait and an enum as well, whatever this returns.
                    class_exists($className);
                } catch (\Throwable $error) {
                    $errorMessageByClassName[$className] = $error->getMessage();
                }
            }
        } finally {
            @unlink($this->markerPath);
        }

        // A class whose core parent this core line lacks cannot run here before or after the swap, so it
        // does not stand in the way of the update.
        self::$hasLoadedEveryClass = [] === array_filter(
            $errorMessageByClassName,
            static fn (string $errorMessage): bool => 1 !== preg_match(self::MISSING_CLASS_MESSAGE, $errorMessage),
        );

        $this->logger?->debug('Preloaded the plugin classes before their files are replaced.', [
            'classes' => \count($classNames),
            'failed' => \count($errorMessageByClassName),
            'milliseconds' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        return $errorMessageByClassName;
    }

    private function writeMarker(): void
    {
        $directory = \dirname($this->markerPath);

        if (!is_dir($directory)) {
            @mkdir($directory, 0o777, true);
        }

        @file_put_contents($this->markerPath, (string) getmypid());
    }
}
