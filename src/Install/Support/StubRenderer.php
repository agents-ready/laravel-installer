<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install\Support;

use RuntimeException;

final readonly class StubRenderer
{
    public function __construct(private ?string $root = null) {}

    /**
     * @param  array<string, string>  $replacements
     */
    public function render(string $path, array $replacements = []): string
    {
        $absolutePath = $this->root().'/'.ltrim($path, '/');

        if (! is_file($absolutePath)) {
            throw new RuntimeException("Unable to read stub [{$path}].");
        }

        $contents = file_get_contents($absolutePath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read stub [{$path}].");
        }

        $tokens = [];

        foreach ($replacements as $key => $value) {
            $tokens['{{ '.$key.' }}'] = $value;
        }

        return strtr($contents, $tokens);
    }

    private function root(): string
    {
        return $this->root ?? dirname(__DIR__, 3).'/stubs';
    }
}
