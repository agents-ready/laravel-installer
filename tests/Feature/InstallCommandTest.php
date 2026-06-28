<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests\Feature;

use AgentsReady\LaravelInstaller\Tests\TestCase;

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
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Wrote local')
            ->expectsOutputToContain('Initialized .env.testing.example')
            ->expectsOutputToContain('Updated AGENTS.md')
            ->expectsOutputToContain('Updated .gitignore')
            ->expectsOutputToContain('Review .env.testing.example before committing; it was generated from local env files and may contain secrets.')
            ->expectsOutputToContain('Optional: rerun with --decision-notes')
            ->expectsOutputToContain('Inspect tracked changes with git diff, then run ./local up. Local state lives in ignored .agents-ready/.')
            ->assertExitCode(0);

        $this->assertFileExists($this->projectPath('local'));
        $this->assertFileExists($this->projectPath('tools/agents-ready/local.sh'));
        $this->assertFileExists($this->projectPath('tools/agents-ready/lib/common.sh'));
        $this->assertFileExists($this->projectPath('tools/agents-ready/lib/worktrees.sh'));
        $this->assertFileExists($this->projectPath('docker/agents-ready/frankenphp/Dockerfile'));
        $this->assertFileExists($this->projectPath('docker/agents-ready/frankenphp/Caddyfile'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agents-ready/php-fpm/Dockerfile'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agents-ready/nginx/default.conf'));
        $this->assertFileExists($this->projectPath('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('docker-compose.shared.yml'));
        $this->assertFileDoesNotExist($this->projectPath('docker-compose.traefik.yml'));
        $this->assertFileExists($this->projectPath('.env.testing.example'));
        $this->assertFileExists($this->projectPath('.agents-ready/config.json'));
        $this->assertFileExists($this->projectPath('.agents-ready/worktree.json'));
        $this->assertFileExists($this->projectPath('.agents-ready/worktrees.json'));
        $this->assertFileExists($this->projectPath('AGENTS.md'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.local.example.md'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.local.md'));
        $this->assertFileDoesNotExist($this->projectPath('.ai/skills/bootstrap-current-checkout/SKILL.md'));
        $this->assertFileDoesNotExist($this->projectPath('docs/decision-notes/README.md'));
        $this->assertStringContainsString('/.agents-ready/', $this->projectFile('.gitignore'));
        $this->assertStringContainsString('/.env.testing', $this->projectFile('.gitignore'));
        $this->assertStringNotContainsString('/.env.testing.example', $this->projectFile('.gitignore'));
        $this->assertStringNotContainsString('/.agents/skills/', $this->projectFile('.gitignore'));
        $this->assertStringNotContainsString('/AGENTS.local.md', $this->projectFile('.gitignore'));
        $this->assertStringContainsString('<!-- agents-ready:start -->', $this->projectFile('AGENTS.md'));
        $this->assertStringContainsString('Agents-Ready Local Tooling', $this->projectFile('AGENTS.md'));

        $worktree = $this->projectJson('.agents-ready/worktree.json');
        $config = $this->projectJson('.agents-ready/config.json');

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

    public function test_install_command_does_not_generate_old_singular_paths_env_vars_or_commands(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->assertExitCode(0);

        $compose = $this->projectFile('docker-compose.yml');
        $local = $this->projectFile('local');
        $common = $this->projectFile('tools/agents-ready/lib/common.sh');

        $this->assertDirectoryDoesNotExist($this->projectPath('.agent-ready'));
        $this->assertFileDoesNotExist($this->projectPath('tools/agent-ready/local.sh'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agent-ready/frankenphp/Dockerfile'));
        $this->assertStringNotContainsString('AGENT_READY_', $compose);
        $this->assertStringNotContainsString('AGENT_READY_', $common);
        $this->assertStringNotContainsString('tools/agent-ready', $local);
        $this->assertStringNotContainsString('docker/agent-ready', $compose);
        $this->assertStringNotContainsString('.agent-ready', $common);
        $this->assertStringContainsString('tools/agents-ready/local.sh', $local);
        $this->assertStringContainsString('AGENTS_READY_APP_HOST', $compose);
        $this->assertStringContainsString('.agents-ready/worktree.json', $common);
    }

    public function test_install_command_can_generate_nginx_php_fpm_with_mysql(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions([
            '--runtime' => 'nginx-php-fpm',
            '--database' => 'mysql',
        ]))->assertExitCode(0);

        $worktree = $this->projectJson('.agents-ready/worktree.json');
        $compose = $this->projectFile('docker-compose.yml');
        $dockerfile = $this->projectFile('docker/agents-ready/php-fpm/Dockerfile');

        $this->assertSame('nginx-php-fpm', $worktree['runtime']);
        $this->assertSame('mysql', $worktree['databaseEngine']);
        $this->assertFileExists($this->projectPath('docker/agents-ready/php-fpm/Dockerfile'));
        $this->assertFileExists($this->projectPath('docker/agents-ready/nginx/default.conf'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agents-ready/frankenphp/Dockerfile'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agents-ready/frankenphp/Caddyfile'));
        $this->assertStringContainsString('context: ./docker/agents-ready/php-fpm', $compose);
        $this->assertStringContainsString('nginx:1.27.4', $compose);
        $this->assertStringContainsString('traefik.http.services.${AGENTS_READY_APP_SERVICE:-command-test-app}.loadbalancer.server.port=80', $compose);
        $this->assertStringContainsString('fastcgi_pass app:9000;', $this->projectFile('docker/agents-ready/nginx/default.conf'));
        $this->assertStringContainsString('php:8.4.15-fpm', $dockerfile);
        $this->assertStringContainsString('composer:2.9.2', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-install pdo_mysql', $dockerfile);
        $this->assertStringNotContainsString('libpq-dev', $dockerfile);
        $this->assertStringNotContainsString('pdo_pgsql', $dockerfile);
    }

    public function test_install_command_can_enable_horizon(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--horizon' => true]))
            ->assertExitCode(0);

        $worktree = $this->projectJson('.agents-ready/worktree.json');
        $compose = $this->projectFile('docker-compose.yml');

        $this->assertSame(true, $worktree['horizon']);
        $this->assertArrayNotHasKey('horizonPrefix', $worktree);
        $this->assertStringContainsString('  horizon:', $compose);
        $this->assertStringContainsString('context: ./docker/agents-ready/frankenphp', $compose);
        $this->assertStringContainsString('command: php artisan horizon', $compose);
        $this->assertStringContainsString('- horizon', $compose);
        $this->assertStringContainsString('HORIZON_PREFIX: ${HORIZON_PREFIX}', $compose);
    }

    public function test_install_command_initializes_local_state_only_when_missing(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Initialized .agents-ready/worktree.json')
            ->assertExitCode(0);

        $customState = json_encode([
            'version' => 1,
            'root' => '/custom/root',
            'mainWorktree' => '/custom/root',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        file_put_contents($this->projectPath('.agents-ready/worktree.json'), $customState);

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->doesntExpectOutputToContain('Initialized .agents-ready/worktree.json')
            ->assertExitCode(0);

        $this->assertSame($customState, $this->projectFile('.agents-ready/worktree.json'));
    }

    public function test_install_command_initializes_env_testing_example_only_when_missing(): void
    {
        file_put_contents($this->projectPath('.env.testing'), "APP_ENV=testing\nFEATURE_FLAG=true\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Initialized .env.testing.example')
            ->expectsOutputToContain('Review .env.testing.example before committing; it was generated from local env files and may contain secrets.')
            ->assertExitCode(0);

        $this->assertSame("APP_ENV=testing\nFEATURE_FLAG=true\n", $this->projectFile('.env.testing.example'));

        file_put_contents($this->projectPath('.env.testing.example'), "CUSTOM_TEMPLATE=true\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->doesntExpectOutputToContain('Initialized .env.testing.example')
            ->doesntExpectOutputToContain('Review .env.testing.example before committing')
            ->assertExitCode(0);

        $this->assertSame("CUSTOM_TEMPLATE=true\n", $this->projectFile('.env.testing.example'));
    }

    public function test_install_command_can_write_optional_decision_notes(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--decision-notes' => true]))
            ->expectsOutputToContain('Wrote docs/decision-notes/README.md')
            ->assertExitCode(0);

        $this->assertFileExists($this->projectPath('docs/decision-notes/README.md'));
    }

    public function test_install_command_can_initialize_local_agent_notes(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--local-agent-notes' => true]))
            ->expectsOutputToContain('Wrote AGENTS.local.example.md')
            ->expectsOutputToContain('Initialized AGENTS.local.md')
            ->assertExitCode(0);

        $this->assertFileExists($this->projectPath('AGENTS.local.example.md'));
        $this->assertFileExists($this->projectPath('AGENTS.local.md'));
        $this->assertStringContainsString('Local Agent Notes', $this->projectFile('AGENTS.local.md'));
    }

    public function test_install_command_can_write_optional_agent_skills(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--agent-skills' => true]))
            ->expectsOutputToContain('Wrote tools/agents-ready/lib/agents.sh')
            ->expectsOutputToContain('Wrote .ai/skills/bootstrap-current-checkout/SKILL.md')
            ->assertExitCode(0);

        $this->assertFileExists($this->projectPath('tools/agents-ready/lib/agents.sh'));
        $this->assertFileExists($this->projectPath('.ai/skills/bootstrap-current-checkout/SKILL.md'));
        $this->assertStringContainsString('/.agents/skills/', $this->projectFile('.gitignore'));
    }

    public function test_install_command_persists_host_root_option_in_local_state(): void
    {
        $hostRoot = $this->root.'-host';

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--host-root' => $hostRoot]))
            ->assertExitCode(0);

        $worktree = $this->projectJson('.agents-ready/worktree.json');
        $index = $this->projectJson('.agents-ready/worktrees.json');

        $this->assertSame($hostRoot, $worktree['root']);
        $this->assertSame($hostRoot, $worktree['mainWorktree']);
        $this->assertSame($hostRoot, $index['worktrees'][0]['path']);
    }

    public function test_install_command_uses_host_root_slug_for_laravel_skeleton_inside_docker_mount(): void
    {
        $this->root = $this->makeLaravelRoot(composerName: 'laravel/laravel');
        $this->app->setBasePath($this->root);
        $hostRoot = dirname($this->root).'/real-agent-app';

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--host-root' => $hostRoot]))
            ->assertExitCode(0);

        $worktree = $this->projectJson('.agents-ready/worktree.json');
        $index = $this->projectJson('.agents-ready/worktrees.json');
        $compose = $this->projectFile('docker-compose.yml');

        $this->assertSame($hostRoot, $worktree['root']);
        $this->assertSame('real-agent-app', $worktree['projectId']);
        $this->assertSame('real-agent-app', $worktree['worktreeId']);
        $this->assertSame('real-agent-app.localhost', $worktree['routeDomain']);
        $this->assertSame('real-agent-app', $index['worktrees'][0]['id']);
        $this->assertSame($hostRoot, $index['worktrees'][0]['path']);
        $this->assertSame('real-agent-app.localhost', $index['worktrees'][0]['appHost']);
        $this->assertStringContainsString('name: ${COMPOSE_PROJECT_NAME:-real-agent-app}', $compose);
        $this->assertStringContainsString('${AGENTS_READY_APP_HOST:-real-agent-app.localhost}', $compose);
        $this->assertStringContainsString('${AGENTS_READY_APP_SERVICE:-real-agent-app}', $compose);
    }

    public function test_install_command_persists_traefik_home_option_in_local_config(): void
    {
        $traefikHome = $this->root.'-shared-traefik';

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--traefik-home' => $traefikHome]))
            ->expectsOutputToContain('Initialized .agents-ready/config.json')
            ->assertExitCode(0);

        $config = $this->projectJson('.agents-ready/config.json');

        $this->assertSame([
            'version' => 1,
            'traefikHome' => $traefikHome,
            'servicesHome' => dirname($this->root).'/shared-services',
        ], $config);
    }

    public function test_install_command_persists_services_home_option_in_local_config(): void
    {
        $servicesHome = $this->root.'-shared-services';

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--services-home' => $servicesHome]))
            ->expectsOutputToContain('Initialized .agents-ready/config.json')
            ->assertExitCode(0);

        $config = $this->projectJson('.agents-ready/config.json');

        $this->assertSame($servicesHome, $config['servicesHome']);
    }

    public function test_project_slug_option_sets_identity_and_default_route_domain(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions([
            '--project-slug' => 'Real App',
        ]))->assertExitCode(0);

        $worktree = $this->projectJson('.agents-ready/worktree.json');
        $compose = $this->projectFile('docker-compose.yml');

        $this->assertSame('real-app', $worktree['projectId']);
        $this->assertSame('real-app', $worktree['worktreeId']);
        $this->assertSame('real-app.localhost', $worktree['routeDomain']);
        $this->assertStringContainsString('name: ${COMPOSE_PROJECT_NAME:-real-app}', $compose);
        $this->assertStringContainsString('${AGENTS_READY_APP_HOST:-real-app.localhost}', $compose);
        $this->assertStringContainsString('${AGENTS_READY_VITE_HOST:-vite.real-app.localhost}', $compose);
    }

    public function test_install_command_rejects_empty_project_slug_after_normalization(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions([
            '--project-slug' => '!!!',
        ]))
            ->expectsOutputToContain('Option --project-slug must include at least one letter or number after normalization.')
            ->assertExitCode(1);
    }

    public function test_route_domain_override_keeps_project_slug_separate(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions([
            '--project-slug' => 'real-app',
            '--route-domain' => 'custom.localhost',
        ]))->assertExitCode(0);

        $worktree = $this->projectJson('.agents-ready/worktree.json');

        $this->assertSame('real-app', $worktree['projectId']);
        $this->assertSame('real-app', $worktree['worktreeId']);
        $this->assertSame('custom.localhost', $worktree['routeDomain']);
        $this->assertStringContainsString('${AGENTS_READY_APP_SERVICE:-real-app}', $this->projectFile('docker-compose.yml'));
        $this->assertStringContainsString('${AGENTS_READY_APP_HOST:-custom.localhost}', $this->projectFile('docker-compose.yml'));
        $this->assertStringContainsString('${AGENTS_READY_VITE_HOST:-vite.custom.localhost}', $this->projectFile('docker-compose.yml'));
    }

    public function test_non_interactive_install_requires_host_root(): void
    {
        $this->artisan('agents-ready:install', ['--no-interaction' => true])
            ->expectsOutputToContain('Option --host-root is required. Pass the real host checkout path, especially when installing from Docker.')
            ->assertExitCode(1);
    }

    public function test_install_requires_traefik_home_even_when_interactive(): void
    {
        $options = $this->nonInteractiveOptions(['--no-interaction' => false]);
        unset($options['--traefik-home']);

        $this->artisan('agents-ready:install', $options)
            ->expectsOutputToContain('Option --traefik-home is required. Pass the absolute path to shared-traefik.')
            ->assertExitCode(1);
    }

    public function test_install_requires_services_home_even_when_interactive(): void
    {
        $options = $this->nonInteractiveOptions(['--no-interaction' => false]);
        unset($options['--services-home']);

        $this->artisan('agents-ready:install', $options)
            ->expectsOutputToContain('Option --services-home is required. Pass the absolute path to shared-services.')
            ->assertExitCode(1);
    }

    public function test_install_requires_host_root_even_when_interactive(): void
    {
        $this->artisan('agents-ready:install', [
            '--traefik-home' => dirname($this->root).'/shared-traefik',
            '--services-home' => dirname($this->root).'/shared-services',
        ])
            ->expectsOutputToContain('Option --host-root is required. Pass the real host checkout path, especially when installing from Docker.')
            ->assertExitCode(1);
    }

    public function test_interactive_install_prompts_for_project_choices(): void
    {
        $hostRoot = $this->root.'-host';

        $this->artisan('agents-ready:install', [
            '--host-root' => $hostRoot,
            '--traefik-home' => $this->root.'-shared-traefik',
            '--services-home' => dirname($this->root).'/shared-services',
        ])
            ->expectsQuestion('Host script path', 'tools/agents-ready')
            ->expectsQuestion('Docker support root path', 'docker/agents-ready')
            ->expectsQuestion('Project slug', 'project')
            ->expectsChoice('Runtime', 'nginx-php-fpm', ['frankenphp', 'nginx-php-fpm'])
            ->expectsChoice('Database', 'mysql', ['pgsql', 'mysql'])
            ->expectsConfirmation('Add local agent notes convention?', 'yes')
            ->expectsConfirmation('Add agent skills convention?', 'no')
            ->expectsConfirmation('Add decision-notes guidance?', 'no')
            ->assertExitCode(0);

        $worktree = $this->projectJson('.agents-ready/worktree.json');
        $config = $this->projectJson('.agents-ready/config.json');

        $this->assertSame($hostRoot, $worktree['root']);
        $this->assertSame('project', $worktree['projectId']);
        $this->assertSame('project', $worktree['worktreeId']);
        $this->assertSame('nginx-php-fpm', $worktree['runtime']);
        $this->assertSame('mysql', $worktree['databaseEngine']);
        $this->assertSame('project.localhost', $worktree['routeDomain']);
        $this->assertSame($this->root.'-shared-traefik', $config['traefikHome']);
        $this->assertSame(dirname($this->root).'/shared-services', $config['servicesHome']);
        $this->assertFileExists($this->projectPath('AGENTS.local.md'));
        $this->assertFileExists($this->projectPath('tools/agents-ready/local.sh'));
        $this->assertFileExists($this->projectPath('docker/agents-ready/php-fpm/Dockerfile'));
    }

    public function test_interactive_install_uses_path_options_without_prompting_for_them(): void
    {
        $hostRoot = $this->root.'-host';

        $this->artisan('agents-ready:install', [
            '--host-root' => $hostRoot,
            '--tools-path' => 'scripts/dev',
            '--docker-path' => 'infra/docker',
            '--traefik-home' => $this->root.'-shared-traefik',
            '--services-home' => $this->root.'-shared-services',
            '--project-slug' => 'command-test-app',
            '--route-domain' => 'project.localhost',
            '--runtime' => 'frankenphp',
            '--database' => 'pgsql',
        ])
            ->expectsConfirmation('Add local agent notes convention?', 'no')
            ->expectsConfirmation('Add agent skills convention?', 'no')
            ->expectsConfirmation('Add decision-notes guidance?', 'no')
            ->assertExitCode(0);

        $worktree = $this->projectJson('.agents-ready/worktree.json');

        $this->assertSame($hostRoot, $worktree['root']);
        $this->assertSame('project.localhost', $worktree['routeDomain']);
        $this->assertFileExists($this->projectPath('.agents-ready/config.json'));
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

        $this->artisan('agents-ready:install', [
            '--host-root' => $this->root,
            '--traefik-home' => dirname($this->root).'/shared-traefik',
            '--services-home' => dirname($this->root).'/shared-services',
        ])
            ->expectsQuestion('Host script path', 'tools/agents-ready')
            ->expectsQuestion('Docker support root path', 'docker/agents-ready')
            ->expectsQuestion('Project slug', 'command-test-app')
            ->expectsChoice('Runtime', 'frankenphp', ['frankenphp', 'nginx-php-fpm'])
            ->expectsChoice('Database', 'pgsql', ['pgsql', 'mysql'])
            ->doesntExpectOutputToContain('Optional: rerun with --decision-notes')
            ->assertExitCode(0);

        $this->assertSame("# Existing local notes\n", $this->projectFile('AGENTS.local.md'));
        $this->assertSame("# Existing skill\n", $this->projectFile('.ai/skills/example/SKILL.md'));
        $this->assertSame("# Existing decisions\n", $this->projectFile('docs/decision-notes/README.md'));
    }

    public function test_install_command_can_write_to_custom_generated_paths(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions([
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
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--tools-path' => '../outside']))
            ->expectsOutputToContain("Option --tools-path must be a non-empty relative path without absolute, '.' or '..' segments.")
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_absolute_and_colliding_generated_paths(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--tools-path' => '/tmp/tools']))
            ->expectsOutputToContain("Option --tools-path must be a non-empty relative path without absolute, '.' or '..' segments.")
            ->assertExitCode(1);

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--tools-path' => 'local']))
            ->expectsOutputToContain('Installation plan target [local/lib/common.sh] is nested under file target [local].')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_relative_host_root(): void
    {
        $this->artisan('agents-ready:install', ['--no-interaction' => true, '--host-root' => 'relative/path'])
            ->expectsOutputToContain('Option --host-root must be an absolute path.')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_relative_traefik_home(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--traefik-home' => 'relative/path']))
            ->expectsOutputToContain('Option --traefik-home must be an absolute path.')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_relative_services_home(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--services-home' => 'relative/path']))
            ->expectsOutputToContain('Option --services-home must be an absolute path.')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_invalid_route_domain(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--route-domain' => 'https://app.localhost']))
            ->expectsOutputToContain('Option --route-domain must be a hostname without a scheme, port, path, whitespace, or invalid DNS labels.')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_invalid_runtime(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--runtime' => 'apache']))
            ->expectsOutputToContain('Option --runtime must be one of: frankenphp, nginx-php-fpm.')
            ->assertExitCode(1);
    }

    public function test_install_command_rejects_invalid_database(): void
    {
        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--database' => 'sqlite']))
            ->expectsOutputToContain('Option --database must be one of: pgsql, mysql.')
            ->assertExitCode(1);
    }

    public function test_install_command_stops_for_existing_local_before_writing_fresh_files(): void
    {
        file_put_contents($this->projectPath('local'), "#!/usr/bin/env bash\necho custom\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Existing local entrypoint: local')
            ->expectsOutputToContain('Agents-Ready stopped before writing generated fresh-install files. User-owned files were left unchanged.')
            ->expectsOutputToContain('Rerun with --existing-project-candidates to write only worktree.docker-compose.yml for review and manual integration.')
            ->assertExitCode(1);

        $this->assertSame("#!/usr/bin/env bash\necho custom\n", $this->projectFile('local'));
        $this->assertFileDoesNotExist($this->projectPath('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agents-ready'));
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

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Compose files: docker-compose.yml')
            ->expectsOutputToContain('Laravel Sail indicators: service laravel.test, vendor/laravel/sail, text "Laravel Sail"')
            ->expectsOutputToContain('User-owned files were left unchanged')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agents-ready'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
    }

    public function test_install_command_stops_for_existing_compose_yaml_and_preserves_env_files(): void
    {
        file_put_contents($this->projectPath('compose.yaml'), "services:\n  app:\n    image: custom\n");
        file_put_contents($this->projectPath('.env'), "APP_ENV=local\nCUSTOM_SECRET=keep\n");
        file_put_contents($this->projectPath('.env.testing'), "APP_ENV=testing\nCUSTOM_SECRET=keep-testing\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Compose files: compose.yaml')
            ->expectsOutputToContain('Env files present: .env, .env.testing')
            ->expectsOutputToContain('Rerun with --existing-project-candidates')
            ->assertExitCode(1);

        $this->assertSame("APP_ENV=local\nCUSTOM_SECRET=keep\n", $this->projectFile('.env'));
        $this->assertSame("APP_ENV=testing\nCUSTOM_SECRET=keep-testing\n", $this->projectFile('.env.testing'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agents-ready'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
    }

    public function test_install_command_stops_for_existing_compose_when_only_worktree_state_exists(): void
    {
        mkdir($this->projectPath('.agents-ready'), 0777, true);
        file_put_contents($this->projectPath('.agents-ready/worktree.json'), "{\"version\": 1}\n");
        file_put_contents($this->projectPath('docker-compose.yml'), "services:\n  app:\n    image: custom\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Compose files: docker-compose.yml')
            ->expectsOutputToContain('Agents-Ready stopped before writing generated fresh-install files. User-owned files were left unchanged.')
            ->assertExitCode(1);

        $this->assertSame("{\"version\": 1}\n", $this->projectFile('.agents-ready/worktree.json'));
        $this->assertSame("services:\n  app:\n    image: custom\n", $this->projectFile('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('.agents-ready/worktrees.json'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
    }

    public function test_install_command_stops_for_existing_docker_compose_yaml_when_only_worktrees_index_exists(): void
    {
        mkdir($this->projectPath('.agents-ready'), 0777, true);
        file_put_contents($this->projectPath('.agents-ready/worktrees.json'), "{\"version\": 1, \"worktrees\": []}\n");
        file_put_contents($this->projectPath('docker-compose.yaml'), "services:\n  app:\n    image: custom\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Compose files: docker-compose.yaml')
            ->expectsOutputToContain('Agents-Ready stopped before writing generated fresh-install files. User-owned files were left unchanged.')
            ->assertExitCode(1);

        $this->assertSame("{\"version\": 1, \"worktrees\": []}\n", $this->projectFile('.agents-ready/worktrees.json'));
        $this->assertSame("services:\n  app:\n    image: custom\n", $this->projectFile('docker-compose.yaml'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('.agents-ready/worktree.json'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
    }

    public function test_install_command_stops_for_existing_docker_support_paths(): void
    {
        mkdir($this->projectPath('docker'), 0777, true);
        file_put_contents($this->projectPath('Dockerfile'), "FROM scratch\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Docker support paths: docker/, Dockerfile')
            ->expectsOutputToContain('Rerun with --existing-project-candidates')
            ->assertExitCode(1);

        $this->assertSame("FROM scratch\n", $this->projectFile('Dockerfile'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agents-ready'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
    }

    public function test_install_command_stops_for_existing_custom_configured_docker_path(): void
    {
        mkdir($this->projectPath('infra/docker'), 0777, true);

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--docker-path' => 'infra/docker']))
            ->expectsOutputToContain('Existing project stack or user-owned workflow files detected.')
            ->expectsOutputToContain('Docker support paths: infra/docker/')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agents-ready'));
    }

    public function test_existing_project_candidates_write_only_worktree_compose_and_preserve_user_files(): void
    {
        file_put_contents($this->projectPath('docker-compose.yml'), "services:\n  app:\n    image: custom\n");
        file_put_contents($this->projectPath('.env'), "APP_ENV=local\nCUSTOM_SECRET=keep\n");
        file_put_contents($this->projectPath('.env.testing'), "APP_ENV=testing\nCUSTOM_SECRET=keep-testing\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions([
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

        $this->assertStringContainsString('Agents-Ready existing-project candidate companion file.', $candidate);
        $this->assertStringContainsString('Review and manually integrate this file', $candidate);
        $this->assertStringContainsString('It is not wired automatically', $candidate);
        $this->assertStringContainsString('context: ./docker/agents-ready/php-fpm', $candidate);
        $this->assertStringContainsString('nginx:1.27.4', $candidate);
        $this->assertStringNotContainsString('context: ./docker/agents-ready/frankenphp', $candidate);
        $this->assertSame("services:\n  app:\n    image: custom\n", $this->projectFile('docker-compose.yml'));
        $this->assertSame("APP_ENV=local\nCUSTOM_SECRET=keep\n", $this->projectFile('.env'));
        $this->assertSame("APP_ENV=testing\nCUSTOM_SECRET=keep-testing\n", $this->projectFile('.env.testing'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('tools/agents-ready/local.sh'));
        $this->assertFileDoesNotExist($this->projectPath('.agents-ready/config.json'));
        $this->assertFileDoesNotExist($this->projectPath('.agents-ready/worktree.json'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agents-ready/php-fpm/Dockerfile'));
        $this->assertFileDoesNotExist($this->projectPath('docker/agents-ready/nginx/default.conf'));

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions([
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

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--existing-project-candidates' => true]))
            ->expectsOutputToContain('Conflict: worktree.docker-compose.yml')
            ->expectsOutputToContain('Existing project candidate generation stopped because existing files would be overwritten.')
            ->expectsOutputToContain('Agents-Ready did not modify any files.')
            ->expectsOutputToContain('Existing worktree.docker-compose.yml differs from the Agents-Ready candidate. Review, move, or remove it before rerunning.')
            ->assertExitCode(1);

        $this->assertSame("# user candidate\nservices: {}\n", $this->projectFile('worktree.docker-compose.yml'));
        $this->assertSame("services:\n  app:\n    image: custom\n", $this->projectFile('docker-compose.yml'));
        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('tools/agents-ready/local.sh'));
        $this->assertFileDoesNotExist($this->projectPath('.env.testing.example'));
        $this->assertDirectoryDoesNotExist($this->projectPath('.agents-ready'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
    }

    public function test_install_command_reports_generated_helper_path_conflict_guidance(): void
    {
        mkdir($this->projectPath('tools/agents-ready'), 0777, true);
        file_put_contents($this->projectPath('tools/agents-ready/local.sh'), "#!/usr/bin/env bash\necho existing\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Conflict: tools/agents-ready/local.sh')
            ->expectsOutputToContain('Existing files under tools/agents-ready/ detected. Rerun with --tools-path=... if that tree is already used by this project.')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->projectPath('local'));
        $this->assertFileDoesNotExist($this->projectPath('.gitignore'));
        $this->assertFileDoesNotExist($this->projectPath('AGENTS.md'));
    }

    public function test_install_command_patches_existing_agents_md_without_overwriting_user_content(): void
    {
        file_put_contents($this->projectPath('AGENTS.md'), "# Existing Agent Notes\n\n- Keep this project-specific guidance.\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--agent-skills' => true]))
            ->expectsOutputToContain('Updated AGENTS.md')
            ->assertExitCode(0);

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--agent-skills' => true]))
            ->doesntExpectOutputToContain('Updated AGENTS.md')
            ->assertExitCode(0);

        $agents = $this->projectFile('AGENTS.md');

        $this->assertStringContainsString('# Existing Agent Notes', $agents);
        $this->assertStringContainsString('- Keep this project-specific guidance.', $agents);
        $this->assertStringContainsString('Agents-Ready Local Tooling', $agents);
        $this->assertStringContainsString('tracked skill sources live in `.ai/skills`', $agents);
        $this->assertSame(1, substr_count($agents, '<!-- agents-ready:start -->'));
        $this->assertSame(1, substr_count($agents, '<!-- agents-ready:end -->'));
    }

    public function test_install_command_replaces_only_the_managed_agents_md_block(): void
    {
        file_put_contents($this->projectPath('AGENTS.md'), implode("\n", [
            '# Existing Agent Notes',
            '',
            '<!-- agents-ready:start -->',
            'Old generated guidance.',
            '<!-- agents-ready:end -->',
            '',
            '- Keep this line after the managed block.',
            '',
        ]));

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions(['--local-agent-notes' => true]))
            ->expectsOutputToContain('Updated AGENTS.md')
            ->assertExitCode(0);

        $agents = $this->projectFile('AGENTS.md');

        $this->assertStringContainsString('# Existing Agent Notes', $agents);
        $this->assertStringContainsString('- Keep this line after the managed block.', $agents);
        $this->assertStringNotContainsString('Old generated guidance.', $agents);
        $this->assertStringContainsString('Also read `AGENTS.local.md` when it exists', $agents);
        $this->assertSame(1, substr_count($agents, '<!-- agents-ready:start -->'));
        $this->assertSame(1, substr_count($agents, '<!-- agents-ready:end -->'));
    }

    public function test_install_command_patches_gitignore_idempotently(): void
    {
        file_put_contents($this->projectPath('.gitignore'), "/vendor\n");

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->expectsOutputToContain('Updated .gitignore')
            ->assertExitCode(0);

        $this->artisan('agents-ready:install', $this->nonInteractiveOptions())
            ->doesntExpectOutputToContain('Updated .gitignore')
            ->assertExitCode(0);

        $gitignore = $this->projectFile('.gitignore');

        $this->assertSame(1, substr_count($gitignore, '/.agents-ready/'));
        $this->assertSame(1, substr_count($gitignore, '/.env.testing'));
        $this->assertSame(0, substr_count($gitignore, '/.env.testing.example'));
        $this->assertSame(0, substr_count($gitignore, '/.agents/skills/'));
        $this->assertSame(0, substr_count($gitignore, '/AGENTS.local.md'));
    }

    private function makeLaravelRoot(string $composerName = 'example/command-test-app', bool $withDetectedTraefik = true, bool $withDetectedServices = true): string
    {
        $parent = $this->temporaryDirectory('agents-ready-installer-command-test-');
        $root = $parent.'/app';

        mkdir($root, 0777, true);

        $this->writeLaravelComposerJson($root, $composerName);
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
        return array_merge([
            '--no-interaction' => true,
            '--host-root' => $this->root,
            '--traefik-home' => dirname($this->root).'/shared-traefik',
            '--services-home' => dirname($this->root).'/shared-services',
        ], $options);
    }
}
