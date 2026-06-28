<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests;

use AgentsReady\LaravelInstaller\LaravelInstallerServiceProvider;
use AgentsReady\LaravelInstaller\Tests\Support\UsesTemporaryPaths;
use AgentsReady\LaravelInstaller\Tests\Support\WritesLaravelComposerJson;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use UsesTemporaryPaths;
    use WritesLaravelComposerJson;

    protected function tearDown(): void
    {
        $this->removeTemporaryPaths();

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelInstallerServiceProvider::class,
        ];
    }
}
