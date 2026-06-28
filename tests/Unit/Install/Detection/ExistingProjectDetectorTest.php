<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests\Unit\Install\Detection;

use AgentsReady\LaravelInstaller\Install\Detection\ExistingProjectDetector;
use AgentsReady\LaravelInstaller\Install\ProjectContext;
use AgentsReady\LaravelInstaller\Tests\UnitTestCase;

final class ExistingProjectDetectorTest extends UnitTestCase
{
    public function test_detects_existing_stack_and_workflow_facts_read_only(): void
    {
        $root = $this->temporaryDirectory('agents-ready-existing-project-test-');
        $composer = $this->laravelComposerJson();
        $composer['require-dev']['laravel/sail'] = '^1.0';

        file_put_contents($root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        file_put_contents($root.'/compose.yaml', "# Laravel Sail\nservices:\n  laravel.test:\n    labels:\n      - traefik.enable=true\nnetworks:\n  traefik-public:\n");
        file_put_contents($root.'/local', "#!/usr/bin/env bash\n");
        file_put_contents($root.'/.env', "APP_ENV=local\n");
        file_put_contents($root.'/.env.testing', "APP_ENV=testing\n");
        mkdir($root.'/docker', 0777, true);
        mkdir($root.'/infra/docker', 0777, true);
        mkdir($root.'/vendor/bin', 0777, true);
        mkdir($root.'/vendor/laravel/sail', 0777, true);
        file_put_contents($root.'/vendor/bin/sail', "#!/usr/bin/env bash\n");

        $facts = (new ExistingProjectDetector)->detect(ProjectContext::fromPath($root), 'infra/docker');

        $this->assertTrue($facts->shouldStopFreshInstall());
        $this->assertSame(['compose.yaml'], $facts->composeFiles);
        $this->assertSame([
            'laravel/sail dependency',
            'vendor/bin/sail',
            'service laravel.test',
            'vendor/laravel/sail',
            'text "Laravel Sail"',
        ], $facts->sailIndicators);
        $this->assertTrue($facts->hasLocalEntrypoint);
        $this->assertSame(['docker/', 'infra/docker/'], $facts->dockerSupportPaths);
        $this->assertTrue($facts->hasTraefikConfiguration);
        $this->assertSame(['.env', '.env.testing'], $facts->envFiles);
        $this->assertContains('Traefik references found in Compose text', $facts->summaryLines());
    }

    public function test_detects_docker_compose_yaml_and_override_files(): void
    {
        $root = $this->temporaryDirectory('agents-ready-existing-project-test-');

        $this->writeLaravelComposerJson($root);
        file_put_contents($root.'/docker-compose.yaml', "services:\n  app:\n    image: custom\n");
        file_put_contents($root.'/docker-compose.override.yml', "services:\n  app:\n    labels:\n      - traefik.enable=true\n");
        file_put_contents($root.'/docker-compose.override.yaml', "services:\n  worker:\n    image: custom\n");

        $facts = (new ExistingProjectDetector)->detect(ProjectContext::fromPath($root), 'docker/agents-ready');

        $this->assertTrue($facts->shouldStopFreshInstall());
        $this->assertSame([
            'docker-compose.yaml',
            'docker-compose.override.yml',
            'docker-compose.override.yaml',
        ], $facts->composeFiles);
        $this->assertTrue($facts->hasTraefikConfiguration);
    }

    public function test_env_files_are_reported_without_blocking_fresh_installs(): void
    {
        $root = $this->temporaryDirectory('agents-ready-existing-project-test-');

        $this->writeLaravelComposerJson($root);
        file_put_contents($root.'/.env', "APP_ENV=local\n");
        file_put_contents($root.'/.env.testing', "APP_ENV=testing\n");

        $facts = (new ExistingProjectDetector)->detect(ProjectContext::fromPath($root), 'docker/agents-ready');

        $this->assertFalse($facts->shouldStopFreshInstall());
        $this->assertTrue($facts->hasAnyFacts());
        $this->assertSame(['.env', '.env.testing'], $facts->envFiles);
    }
}
