<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install\Detection;

use EdgarSedov\AgentReadyInstaller\Install\AgentReadyPath;
use EdgarSedov\AgentReadyInstaller\Install\ProjectContext;

final class ExistingProjectDetector
{
    /** @var array<int, string> */
    private const COMPOSE_FILES = [
        AgentReadyPath::COMPOSE,
        'docker-compose.yaml',
        'docker-compose.override.yml',
        'docker-compose.override.yaml',
        'compose.yaml',
        'compose.yml',
    ];

    public function detect(ProjectContext $context, string $dockerPath): ExistingProjectFacts
    {
        $composeFiles = $this->existingPaths($context->root, self::COMPOSE_FILES);
        $composeText = $this->composeText($context->root, $composeFiles);
        $sailIndicators = $this->sailIndicators($context, $composeText);

        return new ExistingProjectFacts(
            composeFiles: $composeFiles,
            sailIndicators: $sailIndicators,
            hasLocalEntrypoint: $this->pathExists($context->root, AgentReadyPath::LOCAL),
            dockerSupportPaths: $this->dockerSupportPaths($context->root, $dockerPath),
            hasTraefikConfiguration: $this->hasTraefikConfiguration($composeText),
            envFiles: $this->existingPaths($context->root, [AgentReadyPath::ENV, AgentReadyPath::ENV_TESTING]),
        );
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function existingPaths(string $root, array $paths): array
    {
        $existing = [];

        foreach ($paths as $path) {
            if ($this->pathExists($root, $path)) {
                $existing[] = $path;
            }
        }

        return $existing;
    }

    /**
     * @param  array<int, string>  $composeFiles
     */
    private function composeText(string $root, array $composeFiles): string
    {
        $contents = [];

        foreach ($composeFiles as $path) {
            $target = $root.'/'.$path;

            if (! is_file($target)) {
                continue;
            }

            $content = file_get_contents($target);

            if (is_string($content)) {
                $contents[] = $content;
            }
        }

        return implode("\n", $contents);
    }

    /**
     * @return array<int, string>
     */
    private function sailIndicators(ProjectContext $context, string $composeText): array
    {
        $indicators = [];

        if ($this->hasComposerPackage($context, 'laravel/sail')) {
            $indicators[] = 'laravel/sail dependency';
        }

        if ($this->pathExists($context->root, 'vendor/bin/sail')) {
            $indicators[] = 'vendor/bin/sail';
        }

        if (str_contains($composeText, 'laravel.test')) {
            $indicators[] = 'service laravel.test';
        }

        if ($this->pathExists($context->root, 'vendor/laravel/sail') || str_contains($composeText, 'vendor/laravel/sail')) {
            $indicators[] = 'vendor/laravel/sail';
        }

        if (str_contains(strtolower($composeText), 'laravel sail')) {
            $indicators[] = 'text "Laravel Sail"';
        }

        return array_values(array_unique($indicators));
    }

    private function hasComposerPackage(ProjectContext $context, string $package): bool
    {
        foreach (['require', 'require-dev'] as $section) {
            $packages = $context->composer[$section] ?? null;

            if (is_array($packages) && array_key_exists($package, $packages)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function dockerSupportPaths(string $root, string $dockerPath): array
    {
        $paths = [];

        if ($this->pathExists($root, 'docker')) {
            $paths[] = $this->displayPath($root, 'docker');
        }

        if ($this->pathExists($root, 'Dockerfile')) {
            $paths[] = 'Dockerfile';
        }

        $dockerPath = trim($dockerPath, '/');

        if ($dockerPath !== '' && ! str_starts_with($dockerPath, 'docker/') && $dockerPath !== 'docker' && $this->pathExists($root, $dockerPath)) {
            $paths[] = $this->displayPath($root, $dockerPath);
        }

        return array_values(array_unique($paths));
    }

    private function hasTraefikConfiguration(string $composeText): bool
    {
        $lower = strtolower($composeText);

        return str_contains($lower, 'traefik.')
            || str_contains($lower, 'traefik-public')
            || str_contains($lower, 'traefik:');
    }

    private function displayPath(string $root, string $path): string
    {
        $target = $root.'/'.$path;

        return is_dir($target) ? rtrim($path, '/').'/' : $path;
    }

    private function pathExists(string $root, string $path): bool
    {
        $target = $root.'/'.$path;

        return file_exists($target) || is_link($target);
    }
}
