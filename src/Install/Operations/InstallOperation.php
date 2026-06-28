<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install\Operations;

interface InstallOperation
{
    public function path(): string;
}
