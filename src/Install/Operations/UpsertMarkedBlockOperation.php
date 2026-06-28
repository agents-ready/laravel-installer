<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install\Operations;

final readonly class UpsertMarkedBlockOperation implements InstallOperation
{
    public function __construct(
        public string $path,
        public string $startMarker,
        public string $endMarker,
        public string $body,
    ) {}

    public function path(): string
    {
        return $this->path;
    }
}
