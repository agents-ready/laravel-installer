<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install\Plan;

use AgentsReady\LaravelInstaller\Install\Operations\AppendMissingLinesOperation;
use AgentsReady\LaravelInstaller\Install\Operations\UpsertMarkedBlockOperation;
use AgentsReady\LaravelInstaller\Install\Operations\WriteFileOperation;
use AgentsReady\LaravelInstaller\Install\Support\TextFilePatcher;
use RuntimeException;

final class InstallPlanApplier
{
    public function __construct(private readonly TextFilePatcher $textFiles) {}

    public function apply(string $root, InstallationPlan $plan): InstallResult
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $conflicts = $this->conflictPaths($root, $plan);

        if ($conflicts !== []) {
            return new InstallResult([], [], [], $conflicts);
        }

        $written = [];
        $initialized = [];
        $updated = [];

        foreach ($plan->operations() as $operation) {
            if ($operation instanceof WriteFileOperation && $operation->conflictsIfDifferent() && $this->applyWrite($root, $operation)) {
                $written[] = $operation->path;
            }

            if ($operation instanceof WriteFileOperation && $operation->createsIfMissing() && $this->applyCreateIfMissing($root, $operation)) {
                $initialized[] = $operation->path;
            }

            if ($operation instanceof UpsertMarkedBlockOperation && $this->textFiles->upsertMarkedBlock($root, $operation->path, $operation->startMarker, $operation->endMarker, $operation->body)) {
                $updated[] = $operation->path;
            }

            if ($operation instanceof AppendMissingLinesOperation && $this->textFiles->appendMissingLines($root, $operation->path, $operation->lines)) {
                $updated[] = $operation->path;
            }
        }

        return new InstallResult($written, $initialized, $updated, []);
    }

    /**
     * @return array<int, string>
     */
    public function conflictPaths(string $root, InstallationPlan $plan): array
    {
        return $this->conflicts(rtrim($root, DIRECTORY_SEPARATOR), $plan);
    }

    /**
     * @return array<int, string>
     */
    private function conflicts(string $root, InstallationPlan $plan): array
    {
        $conflicts = [];

        foreach ($plan->operations() as $operation) {
            if ($operation instanceof UpsertMarkedBlockOperation && $this->hasIncompleteMarkers($root, $operation)) {
                $conflicts[] = $operation->path;

                continue;
            }

            if (! $operation instanceof WriteFileOperation || ! $operation->conflictsIfDifferent()) {
                continue;
            }

            $target = $this->absolutePath($root, $operation->path);

            if ($this->hasBlockedParentPath($root, $target)) {
                $conflicts[] = $operation->path;

                continue;
            }

            if (! file_exists($target) && ! is_link($target)) {
                continue;
            }

            if ($this->hasSameContent($root, $operation)) {
                continue;
            }

            $conflicts[] = $operation->path;
        }

        return $conflicts;
    }

    private function hasIncompleteMarkers(string $root, UpsertMarkedBlockOperation $operation): bool
    {
        $target = $this->absolutePath($root, $operation->path);

        if (! is_file($target)) {
            return false;
        }

        $existing = file_get_contents($target);

        if (! is_string($existing)) {
            return false;
        }

        $start = strpos($existing, $operation->startMarker);
        $end = strpos($existing, $operation->endMarker);

        if ($start === false && $end === false) {
            return false;
        }

        return $start === false || $end === false || $end < $start;
    }

    private function applyWrite(string $root, WriteFileOperation $operation): bool
    {
        if ($this->isIdentical($root, $operation)) {
            return false;
        }

        if ($this->hasSameContent($root, $operation)) {
            $this->chmod($root, $operation);

            return true;
        }

        $this->put($root, $operation);

        return true;
    }

    private function applyCreateIfMissing(string $root, WriteFileOperation $operation): bool
    {
        $target = $this->absolutePath($root, $operation->path);

        if (file_exists($target) || is_link($target)) {
            return false;
        }

        $this->put($root, $operation);

        return true;
    }

    private function put(string $root, WriteFileOperation $operation): void
    {
        $target = $this->absolutePath($root, $operation->path);
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        if (file_put_contents($target, $operation->content) === false) {
            throw new RuntimeException("Unable to write [{$operation->path}].");
        }

        $this->chmod($root, $operation);
    }

    private function isIdentical(string $root, WriteFileOperation $operation): bool
    {
        return $this->hasSameContent($root, $operation) && $this->hasSameMode($root, $operation);
    }

    private function hasSameContent(string $root, WriteFileOperation $operation): bool
    {
        $target = $this->absolutePath($root, $operation->path);

        return is_file($target) && (string) file_get_contents($target) === $operation->content;
    }

    private function hasSameMode(string $root, WriteFileOperation $operation): bool
    {
        $target = $this->absolutePath($root, $operation->path);

        return is_file($target) && (fileperms($target) & 0777) === $operation->mode;
    }

    private function chmod(string $root, WriteFileOperation $operation): void
    {
        $target = $this->absolutePath($root, $operation->path);

        if (! chmod($target, $operation->mode)) {
            throw new RuntimeException("Unable to chmod [{$operation->path}].");
        }
    }

    private function hasBlockedParentPath(string $root, string $target): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $directory = dirname($target);

        while ($directory !== $root && str_starts_with($directory, $root.DIRECTORY_SEPARATOR)) {
            if (file_exists($directory) && ! is_dir($directory)) {
                return true;
            }

            $directory = dirname($directory);
        }

        return false;
    }

    private function absolutePath(string $root, string $path): string
    {
        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
