<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install\Operations\Enum;

enum ExistingFilePolicy
{
    case ConflictIfDifferent;
    case CreateIfMissing;
}
