<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install\Operations;

interface InstallOperation
{
    public function path(): string;
}
