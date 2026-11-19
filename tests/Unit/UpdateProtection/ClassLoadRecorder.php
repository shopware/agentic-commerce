<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\UpdateProtection;

/**
 * Stands in for the autoloader of classes that are never declared, so a test sees which names the
 * preload asked for.
 *
 * @internal
 */
final class ClassLoadRecorder
{
    public const NAMESPACE_PREFIX = 'Swag\AgenticCommerce\Tests\Unit\UpdateProtection\Undeclared\\';

    /** @var list<string> */
    private array $requestedClassNames = [];

    /** @var list<bool> */
    private array $markerPresence = [];

    private \Closure $autoloader;

    /**
     * @param list<string> $failingClassNames
     * @param list<string> $incompatibleClassNames
     */
    public function __construct(
        private readonly string $markerPath,
        private readonly array $failingClassNames = [],
        private readonly array $incompatibleClassNames = [],
    ) {
        $this->autoloader = function (string $className): void {
            if (!str_starts_with($className, self::NAMESPACE_PREFIX)) {
                return;
            }

            $this->requestedClassNames[] = $className;
            $this->markerPresence[] = is_file($this->markerPath);

            if (\in_array($className, $this->failingClassNames, true)) {
                throw new \Error(\sprintf('Class "%s" not found', $className));
            }

            if (\in_array($className, $this->incompatibleClassNames, true)) {
                throw new \Error(\sprintf('Declaration of %s::build() must be compatible with its parent', $className));
            }
        };

        spl_autoload_register($this->autoloader, true, true);
    }

    public function stop(): void
    {
        spl_autoload_unregister($this->autoloader);
    }

    /**
     * @return list<string>
     */
    public function requestedClassNames(): array
    {
        return $this->requestedClassNames;
    }

    public function wasMarkerPresentOnEveryRequest(): bool
    {
        return [] !== $this->markerPresence && !\in_array(false, $this->markerPresence, true);
    }

    public static function className(string $shortName): string
    {
        return self::NAMESPACE_PREFIX.$shortName;
    }
}
