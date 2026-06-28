<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install\Operations;

use EdgarSedov\AgentReadyInstaller\Install\Operations\Enum\ExistingFilePolicy;

final readonly class WriteFileOperation implements InstallOperation
{
    private function __construct(
        public string $path,
        public string $content,
        public int $mode = 0644,
        public ExistingFilePolicy $existingFilePolicy = ExistingFilePolicy::ConflictIfDifferent,
    ) {}

    public static function conflictIfDifferent(string $path, string $content, int $mode = 0644): self
    {
        return new self($path, $content, $mode, ExistingFilePolicy::ConflictIfDifferent);
    }

    public static function createIfMissing(string $path, string $content, int $mode = 0644): self
    {
        return new self($path, $content, $mode, ExistingFilePolicy::CreateIfMissing);
    }

    public function conflictsIfDifferent(): bool
    {
        return $this->existingFilePolicy === ExistingFilePolicy::ConflictIfDifferent;
    }

    public function createsIfMissing(): bool
    {
        return $this->existingFilePolicy === ExistingFilePolicy::CreateIfMissing;
    }

    public function path(): string
    {
        return $this->path;
    }
}
