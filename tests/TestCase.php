<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Tests;

use EdgarSedov\AgentReadyInstaller\AgentReadyInstallerServiceProvider;
use EdgarSedov\AgentReadyInstaller\Tests\Support\UsesTemporaryPaths;
use EdgarSedov\AgentReadyInstaller\Tests\Support\WritesLaravelComposerJson;
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
            AgentReadyInstallerServiceProvider::class,
        ];
    }
}
