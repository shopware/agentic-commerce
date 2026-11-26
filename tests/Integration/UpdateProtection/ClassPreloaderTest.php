<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Integration\UpdateProtection;

use Composer\ClassMapGenerator\ClassMapGenerator;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use Swag\AgenticCommerce\BundledClassLoading;
use Swag\AgenticCommerce\UpdateProtection\ClassPreloader;

/**
 * Runs the preload over every class the release archive ships, in the booted lane's core. A class
 * whose signature no longer matches its core parent kills this process, which is how a lane shows
 * that the class list built for the archive cannot be used on its core line.
 *
 * @internal
 */
#[Package('framework')]
final class ClassPreloaderTest extends TestCase
{
    private const MISSING_CLASS_MESSAGE = '/^(Class|Interface|Trait) ".+" not found$/';

    private string $classListRoot;

    protected function setUp(): void
    {
        $this->classListRoot = sys_get_temp_dir().'/swag-agentic-commerce-preload-'.bin2hex(random_bytes(6));
        mkdir($this->classListRoot.'/lib', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->classListRoot.BundledClassLoading::CLASS_LIST_FILE, $this->classListRoot.'/var/preload.marker'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach ([$this->classListRoot.'/lib', $this->classListRoot.'/var', $this->classListRoot] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testEveryShippedClassLoadsOrIsMissingAParentOfThisCoreLine(): void
    {
        $classNames = $this->shippedClassNames();
        static::assertGreaterThan(100, \count($classNames), 'The class map of src/ is unexpectedly small.');
        $markerPath = $this->classListRoot.'/var/preload.marker';
        file_put_contents(
            $this->classListRoot.BundledClassLoading::CLASS_LIST_FILE,
            '<?php return '.var_export(['plugin' => $classNames, 'sdk' => []], true).';',
        );

        $errorMessageByClassName = (new ClassPreloader($this->classListRoot, $markerPath))->preload(false);

        $unexpectedErrorByClassName = array_filter(
            $errorMessageByClassName,
            static fn (string $errorMessage): bool => 1 !== preg_match(self::MISSING_CLASS_MESSAGE, $errorMessage),
        );

        if ([] !== $errorMessageByClassName) {
            fwrite(\STDERR, \sprintf("\nClasses that cannot be loaded on this core line:\n%s\n", implode("\n", array_map(
                static fn (string $className, string $errorMessage): string => \sprintf('  %s: %s', $className, $errorMessage),
                array_keys($errorMessageByClassName),
                $errorMessageByClassName,
            ))));
        }

        static::assertSame([], $unexpectedErrorByClassName);
        static::assertFileDoesNotExist($markerPath);
    }

    /**
     * @return list<class-string>
     */
    private function shippedClassNames(): array
    {
        $generator = new ClassMapGenerator();
        $generator->scanPaths(\dirname(__DIR__, 3).'/src', '{/(Ucp/Test|Resources/app)/}');

        /** @var list<class-string> $classNames */
        $classNames = array_keys($generator->getClassMap()->getMap());
        sort($classNames);

        return $classNames;
    }
}
