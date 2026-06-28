<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install\Detection;

final readonly class ExistingProjectFacts
{
    /**
     * @param  array<int, string>  $composeFiles
     * @param  array<int, string>  $sailIndicators
     * @param  array<int, string>  $dockerSupportPaths
     * @param  array<int, string>  $envFiles
     */
    public function __construct(
        public array $composeFiles = [],
        public array $sailIndicators = [],
        public bool $hasLocalEntrypoint = false,
        public array $dockerSupportPaths = [],
        public bool $hasTraefikConfiguration = false,
        public array $envFiles = [],
    ) {}

    public function shouldStopFreshInstall(): bool
    {
        return $this->composeFiles !== []
            || $this->sailIndicators !== []
            || $this->hasLocalEntrypoint
            || $this->dockerSupportPaths !== [];
    }

    public function hasAnyFacts(): bool
    {
        return $this->shouldStopFreshInstall()
            || $this->hasTraefikConfiguration
            || $this->envFiles !== [];
    }

    /**
     * @return array<int, string>
     */
    public function summaryLines(): array
    {
        $lines = [];

        if ($this->composeFiles !== []) {
            $lines[] = 'Compose files: '.implode(', ', $this->composeFiles);
        }

        if ($this->sailIndicators !== []) {
            $lines[] = 'Laravel Sail indicators: '.implode(', ', $this->sailIndicators);
        }

        if ($this->hasLocalEntrypoint) {
            $lines[] = 'Existing local entrypoint: local';
        }

        if ($this->dockerSupportPaths !== []) {
            $lines[] = 'Docker support paths: '.implode(', ', $this->dockerSupportPaths);
        }

        if ($this->hasTraefikConfiguration) {
            $lines[] = 'Traefik references found in Compose text';
        }

        if ($this->envFiles !== []) {
            $lines[] = 'Env files present: '.implode(', ', $this->envFiles);
        }

        return $lines;
    }
}
