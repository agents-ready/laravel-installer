<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller;

use AgentsReady\LaravelInstaller\Console\InstallCommand;
use Illuminate\Support\ServiceProvider;

final class LaravelInstallerServiceProvider extends ServiceProvider
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
