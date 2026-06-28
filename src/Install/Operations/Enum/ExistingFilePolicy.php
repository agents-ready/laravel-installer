<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install\Operations\Enum;

enum ExistingFilePolicy
{
    case ConflictIfDifferent;
    case CreateIfMissing;
}
