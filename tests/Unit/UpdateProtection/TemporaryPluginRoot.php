<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\Tests\Unit\UpdateProtection;

use Swag\AgenticCommerce\BundledClassLoading;

/** @internal */
final class TemporaryPluginRoot
{
    private function __construct(private readonly string $path)
    {
    }

    public static function create(): self
    {
        $path = sys_get_temp_dir().'/swag-agentic-commerce-update-protection-'.bin2hex(random_bytes(6));
        mkdir($path.'/lib', 0o777, true);

        return new self($path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function markerPath(): string
    {
        return $this->path.'/var/preload.marker';
    }

    /**
     * @param list<string> $pluginClassNames
     * @param list<string> $sdkClassNames
     */
    public function writeClassLists(array $pluginClassNames, array $sdkClassNames): void
    {
        file_put_contents(
            $this->path.BundledClassLoading::CLASS_LIST_FILE,
            '<?php return '.var_export(['plugin' => $pluginClassNames, 'sdk' => $sdkClassNames], true).';',
        );
    }

    /**
     * @param list<string> $entryNames
     */
    public function writeArchive(string $fileName, array $entryNames): string
    {
        $archivePath = $this->path.'/'.$fileName;
        $archive = new \ZipArchive();
        $archive->open($archivePath, \ZipArchive::CREATE);

        foreach ($entryNames as $entryName) {
            str_ends_with($entryName, '/') ? $archive->addEmptyDir(rtrim($entryName, '/')) : $archive->addFromString($entryName, 'content');
        }

        $archive->close();

        return $archivePath;
    }

    public function writeMarker(): void
    {
        if (!is_dir(\dirname($this->markerPath()))) {
            mkdir(\dirname($this->markerPath()), 0o777, true);
        }
        file_put_contents($this->markerPath(), '1');
    }

    public function remove(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->path);
    }
}
