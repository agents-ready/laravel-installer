<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests\Unit\Install;

use AgentsReady\LaravelInstaller\Install\ProjectContext;
use AgentsReady\LaravelInstaller\Tests\UnitTestCase;

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

    public function test_generic_laravel_skeleton_name_uses_host_root_basename_when_available(): void
    {
        $context = ProjectContext::fromPath($this->makeProjectRoot('laravel/laravel'));

        $this->assertSame('real-agent-app', $context->slug('/home/example/real-agent-app'));
        $this->assertSame('real-agent-app.localhost', $context->defaultRouteDomain('/home/example/real-agent-app'));
    }

    public function test_host_root_does_not_override_named_composer_package_slug(): void
    {
        $context = ProjectContext::fromPath($this->makeProjectRoot('accredify/nexus'));

        $this->assertSame('nexus', $context->slug('/home/example/real-agent-app'));
        $this->assertSame('nexus.localhost', $context->defaultRouteDomain('/home/example/real-agent-app'));
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

    public function test_normalize_slug_returns_null_for_empty_normalized_input(): void
    {
        $this->assertNull(ProjectContext::normalizeSlug('!!!'));
    }

    private function makeProjectRoot(string $composerName): string
    {
        $root = $this->temporaryDirectory('agents-ready-project-context-test-');

        $this->writeLaravelComposerJson($root, $composerName);

        return $root;
    }
}
