<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Tests\Feature;

use EdgarSedov\AgentReadyInstaller\Tests\TestCase;

final class InstallCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = $this->makeLaravelRoot();
        $this->app->setBasePath($this->root);
    }

    public function test_install_command_writes_the_v1_file_set(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Wrote local')
            ->expectsOutputToContain('Initialized .env.testing.example')
            ->expectsOutputToContain('Updated AGENTS.md')
            ->expectsOutputToContain('Updated .gitignore')
            ->expectsOutputToContain('Review .env.testing.example before committing; it was generated from local env files and may contain secrets.')
            ->expectsOutputToContain('Optional: rerun with --decision-notes')
            ->expectsOutputToContain('Using discovered shared Traefik at')
            ->expectsOutputToContain('Using discovered shared services at')
            ->expectsOutputToContain('Inspect tracked changes with git diff, then run ./local up. Local state lives in ignored .agent-ready/.')
            ->assertExitCode(0);

        $this->assertFileExists($this->projectPath('local'));
        $this->assertFileExists($this->projectPath('tools/agent-ready/local.sh'));
        $this->assertFileExists($this->projectPath('tools/agent-ready/lib/common.sh'));
        $this->assertFileExists($this->projectPath('tools/agent-ready/lib/worktrees.sh'));
        $this->assertFileExists($this->projectPath('docker/agent-ready/frankenphp/Dockerfile'));
        $this->assertFileExists($this->projectPath('docker/agent-ready/frankenphp/Caddyfile'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agent-ready/php-fpm/Dockerfile'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agent-ready/nginx/default.conf'));
        $this->assertFileExists($this->projectPath('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('docker-compose.shared.yml'));
        $this->assertFileDoesNotExist($this->projectPath('docker-compose.traefik.yml'));
        $this->assertFileExists($this->projectPath('.env.testing.example'));
        $this->assertFileExists($this->projectPath('.agent-ready/config.json'));
        $this->assertFileExists($this->projectPath('.agent-ready/worktree.json'));
        $this->assertFileExists($this->projectPath('.agent-ready/worktrees.json'));
        $this->assertFileExists($this->projectPath('AGENTS.md'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.local.example.md'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.local.md'));
        $this->assertFileDoesNotExist($this->projectPath('.ai/skills/bootstrap-current-checkout/SKILL.md'));
        $this->assertFileDoesNotExist($this->projectPath('docs/decision-notes/README.md'));
        $this->assertStringContainsString('/.agent-ready/', $this->projectFile('.gitignore'));
        $this->assertStringContainsString('/.env.testing', $this->projectFile('.gitignore'));
        $this->assertStringNotContainsString('/.env.testing.example', $this->projectFile('.gitignore'));
        $this->assertStringNotContainsString('/.agents/skills/', $this->projectFile('.gitignore'));
        $this->assertStringNotContainsString('/AGENTS.local.md', $this->projectFile('.gitignore'));
        $this->assertStringContainsString('<!-- agent-ready:start -->', $this->projectFile('AGENTS.md'));
        $this->assertStringContainsString('Agent-Ready Local Tooling', $this->projectFile('AGENTS.md'));

        $worktree = $this->projectJson('.agent-ready/worktree.json');
        $config = $this->projectJson('.agent-ready/config.json');

        $this->assertSame('command-test-app', $worktree['projectId']);
        $this->assertSame('command-test-app', $worktree['worktreeId']);
        $this->assertSame('frankenphp', $worktree['runtime']);
        $this->assertSame('pgsql', $worktree['databaseEngine']);
        $this->assertSame(false, $worktree['horizon']);
        $this->assertSame('command-test-app.localhost', $worktree['routeDomain']);
        $this->assertArrayNotHasKey('appHost', $worktree);
        $this->assertArrayNotHasKey('viteHost', $worktree);
        $this->assertArrayNotHasKey('appDatabase', $worktree);
        $this->assertArrayNotHasKey('valkeyPrefix', $worktree);
        $this->assertArrayNotHasKey('horizonPrefix', $worktree);
        $this->assertSame(dirname($this->root).'/shared-traefik', $config['traefikHome']);
        $this->assertSame(dirname($this->root).'/shared-services', $config['servicesHome']);
        $this->assertStringNotContainsString('horizon:', $this->projectFile('docker-compose.yml'));
    }

    public function test_install_command_can_generate_nginx_php_fpm_with_mysql(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions([
            '--runtime' => 'nginx-php-fpm',
            '--database' => 'mysql',
        ]))->assertExitCode(0);

        $worktree = $this->projectJson('.agent-ready/worktree.json');
        $compose = $this->projectFile('docker-compose.yml');
        $dockerfile = $this->projectFile('docker/agent-ready/php-fpm/Dockerfile');

        $this->assertSame('nginx-php-fpm', $worktree['runtime']);
        $this->assertSame('mysql', $worktree['databaseEngine']);
        $this->assertFileExists($this->projectPath('docker/agent-ready/php-fpm/Dockerfile'));
        $this->assertFileExists($this->projectPath('docker/agent-ready/nginx/default.conf'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agent-ready/frankenphp/Dockerfile'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agent-ready/frankenphp/Caddyfile'));
        $this->assertStringContainsString('context: ./docker/agent-ready/php-fpm', $compose);
        $this->assertStringContainsString('nginx:1.27.4', $compose);
        $this->assertStringContainsString('traefik.http.services.${AGENT_READY_APP_SERVICE:-command-test-app}.loadbalancer.server.port=80', $compose);
        $this->assertStringContainsString('fastcgi_pass app:9000;', $this->projectFile('docker/agent-ready/nginx/default.conf'));
        $this->assertStringContainsString('php:8.4.15-fpm', $dockerfile);
        $this->assertStringContainsString('composer:2.9.2', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-install pdo_mysql', $dockerfile);
        $this->assertStringNotContainsString('libpq-dev', $dockerfile);
        $this->assertStringNotContainsString('pdo_pgsql', $dockerfile);
    }

    public function test_install_command_can_enable_horizon(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--horizon' => true]))
            ->assertExitCode(0);

        $worktree = $this->projectJson('.agent-ready/worktree.json');
        $compose = $this->projectFile('docker-compose.yml');

        $this->assertSame(true, $worktree['horizon']);
        $this->assertArrayNotHasKey('horizonPrefix', $worktree);
        $this->assertStringContainsString('  horizon:', $compose);
        $this->assertStringContainsString('context: ./docker/agent-ready/frankenphp', $compose);
        $this->assertStringContainsString('command: php artisan horizon', $compose);
        $this->assertStringContainsString('- horizon', $compose);
        $this->assertStringContainsString('HORIZON_PREFIX: ${HORIZON_PREFIX}', $compose);
    }

    public function test_install_command_initializes_local_state_only_when_missing(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Initialized .agent-ready/worktree.json')
            ->assertExitCode(0);

        $customState = json_encode([
            'version' => 1,
            'root' => '/custom/root',
            'mainWorktree' => '/custom/root',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        file_put_contents($this->projectPath('.agent-ready/worktree.json'), $customState);

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->doesntExpectOutputToContain('Initialized .agent-ready/worktree.json')
            ->assertExitCode(0);

        $this->assertSame($customState, $this->projectFile('.agent-ready/worktree.json'));
    }

    public function test_install_command_initializes_env_testing_example_only_when_missing(): void
    {
        file_put_contents($this->projectPath('.env.testing'), "APP_ENV=testing\nFEATURE_FLAG=true\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Initialized .env.testing.example')
            ->expectsOutputToContain('Review .env.testing.example before committing; it was generated from local env files and may contain secrets.')
            ->assertExitCode(0);

        $this->assertSame("APP_ENV=testing\nFEATURE_FLAG=true\n", $this->projectFile('.env.testing.example'));

        file_put_contents($this->projectPath('.env.testing.example'), "CUSTOM_TEMPLATE=true\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->doesntExpectOutputToContain('Initialized .env.testing.example')
            ->doesntExpectOutputToContain('Review .env.testing.example before committing')
            ->assertExitCode(0);

        $this->assertSame("CUSTOM_TEMPLATE=true\n", $this->projectFile('.env.testing.example'));
    }

    public function test_install_command_can_write_optional_decision_notes(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--decision-notes' => true]))
            ->expectsOutputToContain('Wrote docs/decision-notes/README.md')
            ->assertExitCode(0);

        $this->assertFileExists($this->projectPath('docs/decision-notes/README.md'));
    }

    public function test_install_command_can_initialize_local_agent_notes(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--local-agent-notes' => true]))
            ->expectsOutputToContain('Wrote AGENTS.local.example.md')
            ->expectsOutputToContain('Initialized AGENTS.local.md')
            ->assertExitCode(0);

        $this->assertFileExists($this->projectPath('AGENTS.local.example.md'));
        $this->assertFileExists($this->projectPath('AGENTS.local.md'));
        $this->assertStringContainsString('Local Agent Notes', $this->projectFile('AGENTS.local.md'));
    }

    public function test_install_command_can_write_optional_agent_skills(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--agent-skills' => true]))
            ->expectsOutputToContain('Wrote tools/agent-ready/lib/agents.sh')
            ->expectsOutputToContain('Wrote .ai/skills/bootstrap-current-checkout/SKILL.md')
            ->assertExitCode(0);

        $this->assertFileExists($this->projectPath('tools/agent-ready/lib/agents.sh'));
        $this->assertFileExists($this->projectPath('.ai/skills/bootstrap-current-checkout/SKILL.md'));
        $this->assertStringContainsString('/.agents/skills/', $this->projectFile('.gitignore'));
    }

    public function test_install_command_persists_host_root_option_in_local_state(): void
    {
        $hostRoot = $this->root.'-host';

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--host-root' => $hostRoot]))
            ->assertExitCode(0);

        $worktree = $this->projectJson('.agent-ready/worktree.json');
        $index = $this->projectJson('.agent-ready/worktrees.json');

        $this->assertSame($hostRoot, $worktree['root']);
        $this->assertSame($hostRoot, $worktree['mainWorktree']);
        $this->assertSame($hostRoot, $index['worktrees'][0]['path']);
    }

    public function test_install_command_persists_traefik_home_option_in_local_config(): void
    {
        $traefikHome = $this->root.'-shared-traefik';

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--traefik-home' => $traefikHome]))
            ->expectsOutputToContain('Initialized .agent-ready/config.json')
            ->assertExitCode(0);

        $config = $this->projectJson('.agent-ready/config.json');

        $this->assertSame([
            'version' => 1,
            'traefikHome' => $traefikHome,
            'servicesHome' => dirname($this->root).'/shared-services',
        ], $config);
    }

    public function test_install_command_persists_services_home_option_in_local_config(): void
    {
        $servicesHome = $this->root.'-shared-services';

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--services-home' => $servicesHome]))
            ->expectsOutputToContain('Initialized .agent-ready/config.json')
            ->assertExitCode(0);

        $config = $this->projectJson('.agent-ready/config.json');

        $this->assertSame($servicesHome, $config['servicesHome']);
    }

    public function test_install_command_persists_custom_route_domain_in_local_state(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions([
            '--route-domain' => 'project.localhost',
        ]))->assertExitCode(0);

        $worktree = $this->projectJson('.agent-ready/worktree.json');

        $this->assertSame('project.localhost', $worktree['routeDomain']);
        $this->assertStringContainsString('${AGENT_READY_APP_HOST:-project.localhost}', $this->projectFile('docker-compose.yml'));
        $this->assertStringContainsString('${AGENT_READY_VITE_HOST:-vite.project.localhost}', $this->projectFile('docker-compose.yml'));
    }

    public function test_non_interactive_install_requires_host_root(): void
    {
        $this->artisan('agent-ready:install', ['--no-interaction' => true])
            ->expectsOutputToContain('Option --host-root is required when running non-interactively.')
            ->assertExitCode(1);
    }

    public function test_non_interactive_install_requires_traefik_home_or_detected_sibling(): void
    {
        $this->root = $this->makeLaravelRoot(withDetectedTraefik: false, withDetectedServices: true);
        $this->app->setBasePath($this->root);

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Shared Traefik is required. Pass --traefik-home=/path/to/shared-traefik or keep shared-traefik next to this project.')
            ->assertExitCode(1);
    }

    public function test_non_interactive_install_requires_services_home_or_detected_sibling(): void
    {
        $this->root = $this->makeLaravelRoot(withDetectedTraefik: true, withDetectedServices: false);
        $this->app->setBasePath($this->root);

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Shared services are required. Pass --services-home=/path/to/shared-services or keep shared-services next to this project.')
            ->assertExitCode(1);
    }

    public function test_interactive_install_requires_traefik_home_or_detected_sibling(): void
    {
        $this->root = $this->makeLaravelRoot(withDetectedTraefik: false);
        $this->app->setBasePath($this->root);

        $this->artisan('agent-ready:install')
            ->expectsQuestion('Host project root', $this->root)
            ->expectsQuestion('Host script path', 'tools/agent-ready')
            ->expectsQuestion('Docker support root path', 'docker/agent-ready')
            ->expectsQuestion('Route domain', 'command-test-app.localhost')
            ->expectsQuestion('Shared Traefik path', '')
            ->expectsOutputToContain('Shared Traefik is required. Pass --traefik-home=/path/to/shared-traefik or keep shared-traefik next to this project.')
            ->assertExitCode(1);
    }

    public function test_interactive_install_prompts_for_host_root(): void
    {
        $hostRoot = $this->root.'-host';

        $this->artisan('agent-ready:install')
            ->expectsQuestion('Host project root', $hostRoot)
            ->expectsQuestion('Host script path', 'scripts/dev')
            ->expectsQuestion('Docker support root path', 'infra/docker')
            ->expectsQuestion('Route domain', 'project.localhost')
            ->expectsQuestion('Shared Traefik path', $this->root.'-shared-traefik')
            ->expectsQuestion('Shared services path', dirname($this->root).'/shared-services')
            ->expectsConfirmation('Add local agent notes convention?', 'yes')
            ->expectsConfirmation('Add agent skills convention?', 'no')
            ->expectsConfirmation('Add decision-notes guidance?', 'no')
            ->assertExitCode(0);

        $worktree = $this->projectJson('.agent-ready/worktree.json');
        $config = $this->projectJson('.agent-ready/config.json');

        $this->assertSame($hostRoot, $worktree['root']);
        $this->assertSame('project.localhost', $worktree['routeDomain']);
        $this->assertSame($this->root.'-shared-traefik', $config['traefikHome']);
        $this->assertSame(dirname($this->root).'/shared-services', $config['servicesHome']);
        $this->assertFileExists($this->projectPath('AGENTS.local.md'));
        $this->assertFileExists($this->projectPath('scripts/dev/local.sh'));
        $this->assertFileExists($this->projectPath('infra/docker/frankenphp/Dockerfile'));
    }

    public function test_interactive_install_uses_path_options_without_prompting_for_them(): void
    {
        $hostRoot = $this->root.'-host';

        $this->artisan('agent-ready:install', [
            '--host-root' => $hostRoot,
            '--tools-path' => 'scripts/dev',
            '--docker-path' => 'infra/docker',
            '--traefik-home' => $this->root.'-shared-traefik',
            '--services-home' => $this->root.'-shared-services',
            '--route-domain' => 'project.localhost',
        ])
            ->expectsConfirmation('Add local agent notes convention?', 'no')
            ->expectsConfirmation('Add agent skills convention?', 'no')
            ->expectsConfirmation('Add decision-notes guidance?', 'no')
            ->assertExitCode(0);

        $worktree = $this->projectJson('.agent-ready/worktree.json');

        $this->assertSame($hostRoot, $worktree['root']);
        $this->assertSame('project.localhost', $worktree['routeDomain']);
        $this->assertFileExists($this->projectPath('.agent-ready/config.json'));
        $this->assertFileExists($this->projectPath('scripts/dev/local.sh'));
        $this->assertFileExists($this->projectPath('infra/docker/frankenphp/Dockerfile'));
    }

    public function test_interactive_install_does_not_prompt_for_existing_optional_local_files(): void
    {
        file_put_contents($this->projectPath('AGENTS.local.md'), "# Existing local notes\n");
        mkdir($this->projectPath('.ai/skills/example'), 0777, true);
        file_put_contents($this->projectPath('.ai/skills/example/SKILL.md'), "# Existing skill\n");
        mkdir($this->projectPath('docs/decision-notes'), 0777, true);
        file_put_contents($this->projectPath('docs/decision-notes/README.md'), "# Existing decisions\n");

        $this->artisan('agent-ready:install')
            ->expectsQuestion('Host project root', $this->root)
            ->expectsQuestion('Host script path', 'tools/agent-ready')
            ->expectsQuestion('Docker support root path', 'docker/agent-ready')
            ->expectsQuestion('Route domain', 'command-test-app.localhost')
            ->expectsQuestion('Shared Traefik path', '')
            ->expectsQuestion('Shared services path', dirname($this->root).'/shared-services')
            ->doesntExpectOutputToContain('Optional: rerun with --decision-notes')
            ->assertExitCode(0);

        $this->assertSame("# Existing local notes\n", $this->projectFile('AGENTS.local.md'));
        $this->assertSame("# Existing skill\n", $this->projectFile('.ai/skills/example/SKILL.md'));
        $this->assertSame("# Existing decisions\n", $this->projectFile('docs/decision-notes/README.md'));
    }

    public function test_install_command_can_write_to_custom_generated_paths(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions([
            '--tools-path' => 'scripts/dev',
            '--docker-path' => 'infra/docker',
        ]))
            ->expectsOutputToContain('Wrote scripts/dev/local.sh')
            ->expectsOutputToContain('Wrote infra/docker/frankenphp/Dockerfile')
            ->assertExitCode(0);

        $this->assertFileExists($this->projectPath('scripts/dev/local.sh'));
        $this->assertFileExists($this->projectPath('scripts/dev/lib/common.sh'));
        $this->assertFileExists($this->projectPath('infra/docker/frankenphp/Dockerfile'));
        $this->assertStringContainsString('scripts/dev/local.sh', $this->projectFile('local'));
        $this->assertStringContainsString('context: ./infra/docker/frankenphp', $this->projectFile('docker-compose.yml'));
    }

    public function test_install_command_rejects_unsafe_generated_paths(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--tools-path' => '../outside']))
            ->expectsOutputToContain("Option --tools-path must be a non-empty relative path without absolute, '.' or '..' segments.")
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_absolute_and_colliding_generated_paths(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--tools-path' => '/tmp/tools']))
            ->expectsOutputToContain("Option --tools-path must be a non-empty relative path without absolute, '.' or '..' segments.")
            ->assertExitCode(1);

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--tools-path' => 'local']))
            ->expectsOutputToContain('Installation plan target [local/lib/common.sh] is nested under file target [local].')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_relative_host_root(): void
    {
        $this->artisan('agent-ready:install', ['--no-interaction' => true, '--host-root' => 'relative/path'])
            ->expectsOutputToContain('Option --host-root must be an absolute path.')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_relative_traefik_home(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--traefik-home' => 'relative/path']))
            ->expectsOutputToContain('Option --traefik-home must be an absolute path.')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_relative_services_home(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--services-home' => 'relative/path']))
            ->expectsOutputToContain('Option --services-home must be an absolute path.')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_invalid_route_domain(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--route-domain' => 'https://app.localhost']))
            ->expectsOutputToContain('Option --route-domain must be a hostname without a scheme, port, path, whitespace, or invalid DNS labels.')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_invalid_runtime(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--runtime' => 'apache']))
            ->expectsOutputToContain('Option --runtime must be one of: frankenphp, nginx-php-fpm.')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_invalid_database(): void
    {
        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--database' => 'sqlite']))
            ->expectsOutputToContain('Option --database must be one of: pgsql, mysql.')
            ->assertExitCode(1);
    }

    public function test_install_command_stops_for_existing_local_before_writing_fresh_files(): void
    {
        file_put_contents($this->projectPath('local'), "#!/usr/bin/env bash\necho custom\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Existing local entrypoint: local')
            ->expectsOutputToContain('Agent-Ready stopped before writing generated fresh-install files. User-owned files were left unchanged.')
            ->expectsOutputToContain('Rerun with --existing-project-candidates to write only worktree.docker-compose.yml for review and manual integration.')
            ->assertExitCode(1);

        $this->assertSame("#!/usr/bin/env bash\necho custom\n", $this->projectFile('local'));
        $this->assertFileDoesNotExist($this->projectPath('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agent-ready'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
    }

    public function test_install_command_stops_for_existing_sail_compose_before_writes(): void
    {
        file_put_contents($this->projectPath('docker-compose.yml'), implode("\n", [
            '# Laravel Sail',
            'services:',
            '  laravel.test:',
            '    build:',
            '      context: ./vendor/laravel/sail/runtimes/8.4',
            '',
        ]));

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Compose files: docker-compose.yml')
            ->expectsOutputToContain('Laravel Sail indicators: service laravel.test, vendor/laravel/sail, text "Laravel Sail"')
            ->expectsOutputToContain('User-owned files were left unchanged')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agent-ready'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
    }

    public function test_install_command_stops_for_existing_compose_yaml_and_preserves_env_files(): void
    {
        file_put_contents($this->projectPath('compose.yaml'), "services:\n  app:\n    image: custom\n");
        file_put_contents($this->projectPath('.env'), "APP_ENV=local\nCUSTOM_SECRET=keep\n");
        file_put_contents($this->projectPath('.env.testing'), "APP_ENV=testing\nCUSTOM_SECRET=keep-testing\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Compose files: compose.yaml')
            ->expectsOutputToContain('Env files present: .env, .env.testing')
            ->expectsOutputToContain('Rerun with --existing-project-candidates')
            ->assertExitCode(1);

        $this->assertSame("APP_ENV=local\nCUSTOM_SECRET=keep\n", $this->projectFile('.env'));
        $this->assertSame("APP_ENV=testing\nCUSTOM_SECRET=keep-testing\n", $this->projectFile('.env.testing'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agent-ready'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
    }

    public function test_install_command_stops_for_existing_compose_when_only_worktree_state_exists(): void
    {
        mkdir($this->projectPath('.agent-ready'), 0777, true);
        file_put_contents($this->projectPath('.agent-ready/worktree.json'), "{\"version\": 1}\n");
        file_put_contents($this->projectPath('docker-compose.yml'), "services:\n  app:\n    image: custom\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Compose files: docker-compose.yml')
            ->expectsOutputToContain('Agent-Ready stopped before writing generated fresh-install files. User-owned files were left unchanged.')
            ->assertExitCode(1);

        $this->assertSame("{\"version\": 1}\n", $this->projectFile('.agent-ready/worktree.json'));
        $this->assertSame("services:\n  app:\n    image: custom\n", $this->projectFile('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('.agent-ready/worktrees.json'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
    }

    public function test_install_command_stops_for_existing_docker_compose_yaml_when_only_worktrees_index_exists(): void
    {
        mkdir($this->projectPath('.agent-ready'), 0777, true);
        file_put_contents($this->projectPath('.agent-ready/worktrees.json'), "{\"version\": 1, \"worktrees\": []}\n");
        file_put_contents($this->projectPath('docker-compose.yaml'), "services:\n  app:\n    image: custom\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Compose files: docker-compose.yaml')
            ->expectsOutputToContain('Agent-Ready stopped before writing generated fresh-install files. User-owned files were left unchanged.')
            ->assertExitCode(1);

        $this->assertSame("{\"version\": 1, \"worktrees\": []}\n", $this->projectFile('.agent-ready/worktrees.json'));
        $this->assertSame("services:\n  app:\n    image: custom\n", $this->projectFile('docker-compose.yaml'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('.agent-ready/worktree.json'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
    }

    public function test_install_command_stops_for_existing_docker_support_paths(): void
    {
        mkdir($this->projectPath('docker'), 0777, true);
        file_put_contents($this->projectPath('Dockerfile'), "FROM scratch\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Docker support paths: docker/, Dockerfile')
            ->expectsOutputToContain('Rerun with --existing-project-candidates')
            ->assertExitCode(1);

        $this->assertSame("FROM scratch\n", $this->projectFile('Dockerfile'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agent-ready'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
    }

    public function test_install_command_stops_for_existing_custom_configured_docker_path(): void
    {
        mkdir($this->projectPath('infra/docker'), 0777, true);

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--docker-path' => 'infra/docker']))
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Docker support paths: infra/docker/')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agent-ready'));
    }

    public function test_existing_project_candidates_write_only_worktree_compose_and_preserve_user_files(): void
    {
        file_put_contents($this->projectPath('docker-compose.yml'), "services:\n  app:\n    image: custom\n");
        file_put_contents($this->projectPath('.env'), "APP_ENV=local\nCUSTOM_SECRET=keep\n");
        file_put_contents($this->projectPath('.env.testing'), "APP_ENV=testing\nCUSTOM_SECRET=keep-testing\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions([
            '--existing-project-candidates' => true,
            '--runtime' => 'nginx-php-fpm',
            '--database' => 'mysql',
        ]))
            ->expectsOutputToContain('Detected existing project facts:')
            ->expectsOutputToContain('Compose files: docker-compose.yml')
            ->expectsOutputToContain('Env files present: .env, .env.testing')
            ->expectsOutputToContain('Wrote worktree.docker-compose.yml')
            ->expectsOutputToContain('Only worktree.docker-compose.yml was considered. User-owned files were left unchanged, and the candidate is not wired automatically.')
            ->assertExitCode(0);

        $candidate = $this->projectFile('worktree.docker-compose.yml');

        $this->assertStringContainsString('Agent-Ready existing-project candidate companion file.', $candidate);
        $this->assertStringContainsString('Review and manually integrate this file', $candidate);
        $this->assertStringContainsString('It is not wired automatically', $candidate);
        $this->assertStringContainsString('context: ./docker/agent-ready/php-fpm', $candidate);
        $this->assertStringContainsString('nginx:1.27.4', $candidate);
        $this->assertStringNotContainsString('context: ./docker/agent-ready/frankenphp', $candidate);
        $this->assertSame("services:\n  app:\n    image: custom\n", $this->projectFile('docker-compose.yml'));
        $this->assertSame("APP_ENV=local\nCUSTOM_SECRET=keep\n", $this->projectFile('.env'));
        $this->assertSame("APP_ENV=testing\nCUSTOM_SECRET=keep-testing\n", $this->projectFile('.env.testing'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('tools/agent-ready/local.sh'));
        $this->assertFileDoesNotExist($this->projectPath('.agent-ready/config.json'));
        $this->assertFileDoesNotExist($this->projectPath('.agent-ready/worktree.json'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agent-ready/php-fpm/Dockerfile'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agent-ready/nginx/default.conf'));

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions([
            '--existing-project-candidates' => true,
            '--runtime' => 'nginx-php-fpm',
            '--database' => 'mysql',
        ]))
            ->expectsOutputToContain('Existing project candidate files were already up to date.')
            ->assertExitCode(0);

        $this->assertSame($candidate, $this->projectFile('worktree.docker-compose.yml'));
    }

    public function test_existing_project_candidates_refuse_differing_worktree_compose_before_writes(): void
    {
        file_put_contents($this->projectPath('docker-compose.yml'), "services:\n  app:\n    image: custom\n");
        file_put_contents($this->projectPath('worktree.docker-compose.yml'), "# user candidate\nservices: {}\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--existing-project-candidates' => true]))
            ->expectsOutputToContain('Conflict: worktree.docker-compose.yml')
            ->expectsOutputToContain('Existing project candidate generation stopped because existing files would be overwritten.')
            ->expectsOutputToContain('Agent-Ready did not modify any files.')
            ->expectsOutputToContain('Existing worktree.docker-compose.yml differs from the Agent-Ready candidate. Review, move, or remove it before rerunning.')
            ->assertExitCode(1);

        $this->assertSame("# user candidate\nservices: {}\n", $this->projectFile('worktree.docker-compose.yml'));
        $this->assertSame("services:\n  app:\n    image: custom\n", $this->projectFile('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('tools/agent-ready/local.sh'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agent-ready'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
    }

    public function test_install_command_reports_generated_helper_path_conflict_guidance(): void
    {
        mkdir($this->projectPath('tools/agent-ready'), 0777, true);
        file_put_contents($this->projectPath('tools/agent-ready/local.sh'), "#!/usr/bin/env bash\necho existing\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Conflict: tools/agent-ready/local.sh')
            ->expectsOutputToContain('Existing files under tools/agent-ready/ detected. Rerun with --tools-path=... if that tree is already used by this project.')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
    }

    public function test_install_command_patches_existing_agents_md_without_overwriting_user_content(): void
    {
        file_put_contents($this->projectPath('AGENTS.md'), "# Existing Agent Notes\n\n- Keep this project-specific guidance.\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--agent-skills' => true]))
            ->expectsOutputToContain('Updated AGENTS.md')
            ->assertExitCode(0);

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--agent-skills' => true]))
            ->doesntExpectOutputToContain('Updated AGENTS.md')
            ->assertExitCode(0);

        $agents = $this->projectFile('AGENTS.md');

        $this->assertStringContainsString('# Existing Agent Notes', $agents);
        $this->assertStringContainsString('- Keep this project-specific guidance.', $agents);
        $this->assertStringContainsString('Agent-Ready Local Tooling', $agents);
        $this->assertStringContainsString('tracked skill sources live in `.ai/skills`', $agents);
        $this->assertSame(1, substr_count($agents, '<!-- agent-ready:start -->'));
        $this->assertSame(1, substr_count($agents, '<!-- agent-ready:end -->'));
    }

    public function test_install_command_replaces_only_the_managed_agents_md_block(): void
    {
        file_put_contents($this->projectPath('AGENTS.md'), implode("\n", [
            '# Existing Agent Notes',
            '',
            '<!-- agent-ready:start -->',
            'Old generated guidance.',
            '<!-- agent-ready:end -->',
            '',
            '- Keep this line after the managed block.',
            '',
        ]));

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions(['--local-agent-notes' => true]))
            ->expectsOutputToContain('Updated AGENTS.md')
            ->assertExitCode(0);

        $agents = $this->projectFile('AGENTS.md');

        $this->assertStringContainsString('# Existing Agent Notes', $agents);
        $this->assertStringContainsString('- Keep this line after the managed block.', $agents);
        $this->assertStringNotContainsString('Old generated guidance.', $agents);
        $this->assertStringContainsString('Also read `AGENTS.local.md` when it exists', $agents);
        $this->assertSame(1, substr_count($agents, '<!-- agent-ready:start -->'));
        $this->assertSame(1, substr_count($agents, '<!-- agent-ready:end -->'));
    }

    public function test_install_command_patches_gitignore_idempotently(): void
    {
        file_put_contents($this->projectPath('.gitignore'), "/vendor\n");

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Updated .gitignore')
            ->assertExitCode(0);

        $this->artisan('agent-ready:install', $this->nonInteractiveOptions())
            ->doesntExpectOutputToContain('Updated .gitignore')
            ->assertExitCode(0);

        $gitignore = $this->projectFile('.gitignore');

        $this->assertSame(1, substr_count($gitignore, '/.agent-ready/'));
        $this->assertSame(1, substr_count($gitignore, '/.env.testing'));
        $this->assertSame(0, substr_count($gitignore, '/.env.testing.example'));
        $this->assertSame(0, substr_count($gitignore, '/.agents/skills/'));
        $this->assertSame(0, substr_count($gitignore, '/AGENTS.local.md'));
    }

    private function makeLaravelRoot(bool $withDetectedTraefik = true, bool $withDetectedServices = true): string
    {
        $parent = $this->temporaryDirectory('agent-ready-installer-command-test-');
        $root = $parent.'/app';

        mkdir($root, 0777, true);

        $this->writeLaravelComposerJson($root, 'example/command-test-app');
        file_put_contents($root.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\n");

        if ($withDetectedTraefik) {
            mkdir($parent.'/shared-traefik/bin', 0777, true);
            file_put_contents($parent.'/shared-traefik/bin/ensure', "#!/usr/bin/env bash\n");
            chmod($parent.'/shared-traefik/bin/ensure', 0755);
        }

        if ($withDetectedServices) {
            mkdir($parent.'/shared-services/bin', 0777, true);
            file_put_contents($parent.'/shared-services/bin/ensure', "#!/usr/bin/env bash\n");
            chmod($parent.'/shared-services/bin/ensure', 0755);
        }

        return $root;
    }

    private function projectPath(string $path): string
    {
        return $this->root.'/'.ltrim($path, '/');
    }

    private function projectFile(string $path): string
    {
        return (string) file_get_contents($this->projectPath($path));
    }

    /**
     * @return array<string, mixed>
     */
    private function projectJson(string $path): array
    {
        $decoded = json_decode($this->projectFile($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            $this->fail("Expected [{$path}] to contain a JSON object.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function nonInteractiveOptions(array $options = []): array
    {
        return array_merge(['--no-interaction' => true, '--host-root' => $this->root], $options);
    }
}
