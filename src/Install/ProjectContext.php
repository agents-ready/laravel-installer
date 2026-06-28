<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install;

final readonly class ProjectContext
{
    /**
     * @param  array<string, mixed>  $composer
     */
    private function __construct(
        public string $root,
        public array $composer,
    ) {}

    public static function fromPath(string $root): self
    {
        $composerPath = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'composer.json';
        $composer = [];

        if (is_file($composerPath)) {
            $decoded = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
            $composer = is_array($decoded) ? $decoded : [];
        }

        return new self(rtrim($root, DIRECTORY_SEPARATOR), $composer);
    }

    public function slug(?string $hostRoot = null): string
    {
        $name = $this->composer['name'] ?? null;

        if ($name === null || $name === 'laravel/laravel') {
            $name = basename($hostRoot ?? $this->root);
        }

        return $this->slugFrom((string) $name) ?? 'laravel-app';
    }

    public function defaultRouteDomain(?string $hostRoot = null): string
    {
        return $this->slug($hostRoot).'.localhost';
    }

    public static function normalizeSlug(string $value): ?string
    {
        $base = str_contains($value, '/') ? substr($value, strrpos($value, '/') + 1) : $value;
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $base));
        $slug = trim($slug, '-');

        return $slug !== '' ? substr($slug, 0, 48) : null;
    }

    private function slugFrom(string $value): ?string
    {
        return self::normalizeSlug($value);
    }
}
