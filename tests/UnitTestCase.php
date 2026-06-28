<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests;

use AgentsReady\LaravelInstaller\Tests\Support\UsesTemporaryPaths;
use AgentsReady\LaravelInstaller\Tests\Support\WritesLaravelComposerJson;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class UnitTestCase extends PHPUnitTestCase
{
    use UsesTemporaryPaths;
    use WritesLaravelComposerJson;

    protected function tearDown(): void
    {
        $this->removeTemporaryPaths();

        parent::tearDown();
    }
}
