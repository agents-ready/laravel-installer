<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install\Operations;

final readonly class AppendMissingLinesOperation implements InstallOperation
{
    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public string $path,
        public array $lines,
    ) {}

    public function path(): string
    {
        return $this->path;
    }
}
