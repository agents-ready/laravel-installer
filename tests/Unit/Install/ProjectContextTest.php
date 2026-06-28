<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Tests\Unit\Install;

use EdgarSedov\AgentReadyInstaller\Install\ProjectContext;
use EdgarSedov\AgentReadyInstaller\Tests\UnitTestCase;

final class ProjectContextTest extends UnitTestCase
{
    public function test_slug_and_default_route_domain_use_composer_package_basename(): void
    {
        $context = ProjectContext::fromPath($this->makeProjectRoot('accredify/nexus'));

        $this->assertSame('nexus', $context->slug());
        $this->assertSame('nexus.localhost', $context->defaultRouteDomain());
    }

    public function test_generic_laravel_skeleton_name_uses_directory_basename(): void
    {
        $root = $this->makeProjectRoot('laravel/laravel');
        $context = ProjectContext::fromPath($root);
        $expected = basename($root);

        $this->assertSame($expected, $context->slug());
        $this->assertSame($expected.'.localhost', $context->defaultRouteDomain());
    }

    public function test_slug_is_normalized_for_local_resource_names(): void
    {
        $context = ProjectContext::fromPath($this->makeProjectRoot('Acme/Customer Portal!!!'));

        $this->assertSame('customer-portal', $context->slug());
        $this->assertSame('customer-portal.localhost', $context->defaultRouteDomain());
    }

    public function test_empty_normalized_name_falls_back_to_laravel_app(): void
    {
        $context = ProjectContext::fromPath($this->makeProjectRoot('acme/!!!'));

        $this->assertSame('laravel-app', $context->slug());
        $this->assertSame('laravel-app.localhost', $context->defaultRouteDomain());
    }

    private function makeProjectRoot(string $composerName): string
    {
        $root = $this->temporaryDirectory('agent-ready-project-context-test-');

        $this->writeLaravelComposerJson($root, $composerName);

        return $root;
    }
}
