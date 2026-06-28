<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install\Plan;

use EdgarSedov\AgentReadyInstaller\Install\Operations\InstallOperation;
use InvalidArgumentException;

final readonly class InstallationPlan
{
    /**
     * @param  array<int, InstallOperation>  $operations
     */
    public function __construct(private array $operations)
    {
        $this->validatePaths();
    }

    /**
     * @return array<int, InstallOperation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * @return array<int, string>
     */
    public function paths(): array
    {
        return array_map(
            static fn (InstallOperation $operation): string => $operation->path(),
            $this->operations,
        );
    }

    public function operationFor(string $path): InstallOperation
    {
        foreach ($this->operations as $operation) {
            if ($operation->path() === $path) {
                return $operation;
            }
        }

        throw new InvalidArgumentException("No planned operation exists for [{$path}].");
    }

    private function validatePaths(): void
    {
        $paths = $this->paths();
        $seen = [];

        foreach ($paths as $path) {
            if (isset($seen[$path])) {
                throw new InvalidArgumentException("Installation plan contains duplicate target path [{$path}].");
            }

            $seen[$path] = true;
        }

        sort($paths);

        foreach ($paths as $index => $path) {
            foreach (array_slice($paths, $index + 1) as $candidate) {
                if (str_starts_with($candidate, $path.'/')) {
                    throw new InvalidArgumentException("Installation plan target [{$candidate}] is nested under file target [{$path}].");
                }
            }
        }
    }
}
