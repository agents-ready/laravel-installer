<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait UsesTemporaryPaths
{
    /**
     * @var array<int, string>
     */
    private array $temporaryPaths = [];

    protected function temporaryPath(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));

        $this->temporaryPaths[] = $path;

        return $path;
    }

    protected function temporaryDirectory(string $prefix): string
    {
        $path = $this->temporaryPath($prefix);
        mkdir($path, 0777, true);

        return $path;
    }

    protected function removeTemporaryPaths(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            $this->removePath($path);
        }

        $this->temporaryPaths = [];
    }

    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}
