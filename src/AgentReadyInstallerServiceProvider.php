<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller;

use EdgarSedov\AgentReadyInstaller\Console\InstallCommand;
use Illuminate\Support\ServiceProvider;

final class AgentReadyInstallerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
        ]);
    }
}
