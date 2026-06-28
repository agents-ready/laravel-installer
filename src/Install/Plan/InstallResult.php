<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install\Plan;

final readonly class InstallResult
{
    /**
     * @param  array<int, string>  $writtenPaths
     * @param  array<int, string>  $initializedPaths
     * @param  array<int, string>  $updatedPaths
     * @param  array<int, string>  $conflictPaths
     */
    public function __construct(
        public array $writtenPaths,
        public array $initializedPaths,
        public array $updatedPaths,
        public array $conflictPaths,
    ) {}

    public function successful(): bool
    {
        return $this->conflictPaths === [];
    }
}
