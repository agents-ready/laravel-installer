<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Tests;

use EdgarSedov\AgentReadyInstaller\Tests\Support\UsesTemporaryPaths;
use EdgarSedov\AgentReadyInstaller\Tests\Support\WritesLaravelComposerJson;
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
