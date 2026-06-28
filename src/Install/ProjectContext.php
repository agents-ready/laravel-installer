<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install;

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

    public function slug(): string
    {
        $name = $this->composer['name'] ?? null;

        if ($name === null || $name === 'laravel/laravel') {
            $name = basename($this->root);
        }

        $base = str_contains((string) $name, '/') ? substr((string) $name, strrpos((string) $name, '/') + 1) : (string) $name;
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $base));
        $slug = trim($slug, '-');

        return $slug !== '' ? substr($slug, 0, 48) : 'laravel-app';
    }

    public function defaultRouteDomain(): string
    {
        return $this->slug().'.localhost';
    }
}
