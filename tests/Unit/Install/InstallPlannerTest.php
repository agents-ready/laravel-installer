<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Tests\Unit\Install;

use EdgarSedov\AgentReadyInstaller\Install\InstallOptions;
use EdgarSedov\AgentReadyInstaller\Install\InstallPlanner;
use EdgarSedov\AgentReadyInstaller\Install\Operations\AppendMissingLinesOperation;
use EdgarSedov\AgentReadyInstaller\Install\Operations\UpsertMarkedBlockOperation;
use EdgarSedov\AgentReadyInstaller\Install\Operations\WriteFileOperation;
use EdgarSedov\AgentReadyInstaller\Install\Plan\InstallationPlan;
use EdgarSedov\AgentReadyInstaller\Install\ProjectContext;
use EdgarSedov\AgentReadyInstaller\Tests\UnitTestCase;

final class InstallPlannerTest extends UnitTestCase
{
    public function test_default_v1_plan_contains_typed_install_operations(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );

        $paths = $plan->paths();

        $this->assertContains('local', $paths);
        $this->assertContains('tools/agent-ready/local.sh', $paths);
        $this->assertContains('tools/agent-ready/lib/common.sh', $paths);
        $this->assertContains('tools/agent-ready/lib/worktrees.sh', $paths);
        $this->assertContains('docker-compose.yml', $paths);
        $this->assertNotContains('docker-compose.shared.yml', $paths);
        $this->assertContains('docker/agent-ready/frankenphp/Dockerfile', $paths);
        $this->assertContains('docker/agent-ready/frankenphp/Caddyfile', $paths);
        $this->assertNotContains('docker/agent-ready/php-fpm/Dockerfile', $paths);
        $this->assertNotContains('docker/agent-ready/nginx/default.conf', $paths);
        $this->assertContains('opencode.jsonc.example', $paths);
        $this->assertContains('.env.testing.example', $paths);
        $this->assertNotContains('AGENTS.local.example.md', $paths);
        $this->assertNotContains('tools/agent-ready/lib/agents.sh', $paths);
        $this->assertNotContains('.ai/skills/bootstrap-current-checkout/SKILL.md', $paths);
        $this->assertNotContains('.ai/skills/worktree-lifecycle/SKILL.md', $paths);
        $this->assertNotContains('.agent-ready/config.json', $paths);
        $this->assertContains('.agent-ready/worktree.json', $paths);
        $this->assertContains('.agent-ready/worktrees.json', $paths);
        $this->assertNotContains('docs/decision-notes/README.md', $paths);
        $this->assertNotContains('opencode.jsonc', $paths);
        $this->assertNotContains('.mcp.json', $paths);
        $this->assertContains('AGENTS.md', $paths);
        $this->assertContains('.gitignore', $paths);
        $this->assertInstanceOf(WriteFileOperation::class, $plan->operationFor('local'));
        $this->assertTrue($this->writeOperationFor($plan, '.env.testing.example')->createsIfMissing());
        $this->assertTrue($this->writeOperationFor($plan, '.agent-ready/worktree.json')->createsIfMissing());
        $this->assertInstanceOf(UpsertMarkedBlockOperation::class, $plan->operationFor('AGENTS.md'));
        $gitignoreOperation = $plan->operationFor('.gitignore');

        $this->assertInstanceOf(AppendMissingLinesOperation::class, $gitignoreOperation);
        $this->assertContains('/.env.testing', $gitignoreOperation->lines);
        $this->assertNotContains('/.env.testing.example', $gitignoreOperation->lines);
    }

    public function test_env_testing_example_is_initialized_from_existing_testing_env_first(): void
    {
        $root = $this->makeProjectRoot();

        file_put_contents($root.'/.env.example', "APP_NAME=Example\n");
        file_put_contents($root.'/.env', "APP_NAME=Local\n");
        file_put_contents($root.'/.env.testing', "APP_ENV=testing\n");

        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($root),
            $this->installOptions(),
        );

        $this->assertSame("APP_ENV=testing\n", $this->contentFor($plan, '.env.testing.example'));
    }

    public function test_env_testing_example_falls_back_to_env_then_env_example(): void
    {
        $root = $this->makeProjectRoot();

        file_put_contents($root.'/.env.example', "APP_NAME=Example\n");
        file_put_contents($root.'/.env', "APP_NAME=Local\n");

        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($root),
            $this->installOptions(),
        );

        $this->assertSame("APP_NAME=Local\n", $this->contentFor($plan, '.env.testing.example'));

        unlink($root.'/.env');

        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($root),
            $this->installOptions(),
        );

        $this->assertSame("APP_NAME=Example\n", $this->contentFor($plan, '.env.testing.example'));
    }

    public function test_decision_notes_are_optional(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(decisionNotes: true),
        );

        $paths = $plan->paths();

        $this->assertContains('docs/decision-notes/README.md', $paths);
        $this->assertStringContainsString(
            'Decision Notes',
            $this->contentFor($plan, 'docs/decision-notes/README.md'),
        );
    }

    public function test_plan_contains_create_if_missing_ignored_lifecycle_state(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );

        $paths = $plan->paths();

        $this->assertContains('.agent-ready/worktree.json', $paths);
        $this->assertContains('.agent-ready/worktrees.json', $paths);
        $this->assertNotContains('AGENTS.local.md', $paths);
        $this->assertTrue($this->writeOperationFor($plan, '.agent-ready/worktree.json')->createsIfMissing());
        $this->assertTrue($this->writeOperationFor($plan, '.agent-ready/worktrees.json')->createsIfMissing());
    }

    public function test_companion_config_is_optional_ignored_local_state(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(traefikHome: '/home/example/shared-traefik', servicesHome: '/home/example/shared-services'),
        );

        $config = json_decode($this->contentFor($plan, '.agent-ready/config.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($this->writeOperationFor($plan, '.agent-ready/config.json')->createsIfMissing());
        $this->assertSame([
            'version' => 1,
            'traefikHome' => '/home/example/shared-traefik',
            'servicesHome' => '/home/example/shared-services',
        ], $config);
    }

    public function test_local_agent_notes_are_optional_ignored_local_state(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(localAgentNotes: true),
        );

        $this->assertContains('AGENTS.local.example.md', $plan->paths());
        $this->assertContains('AGENTS.local.md', $plan->paths());
        $this->assertTrue($this->writeOperationFor($plan, 'AGENTS.local.md')->createsIfMissing());
        $agentsSection = (new InstallPlanner)->agentsSection($this->installOptions(localAgentNotes: true));

        $this->assertStringContainsString('Also read `AGENTS.local.md` when it exists', $agentsSection);
        $this->assertStringContainsString('do not commit it', $agentsSection);
        $this->assertStringContainsString('Local Agent Notes', $this->contentFor($plan, 'AGENTS.local.md'));
    }

    public function test_agent_skills_are_optional(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(agentSkills: true),
        );

        $paths = $plan->paths();

        $this->assertContains('tools/agent-ready/lib/agents.sh', $paths);
        $this->assertContains('.ai/skills/bootstrap-current-checkout/SKILL.md', $paths);
        $this->assertContains('.ai/skills/worktree-lifecycle/SKILL.md', $paths);
        $this->assertStringContainsString('agents link', $this->contentFor($plan, 'tools/agent-ready/local.sh'));
        $this->assertStringContainsString('./local agents link', $this->contentFor($plan, 'tools/agent-ready/lib/worktrees.sh'));
        $this->assertStringContainsString('"skill": [".agents/skills"]', $this->contentFor($plan, 'opencode.jsonc.example'));
        $agentsSection = (new InstallPlanner)->agentsSection($this->installOptions(agentSkills: true));

        $this->assertStringContainsString('tracked skill sources live in `.ai/skills`', $agentsSection);
        $this->assertStringContainsString('Run `./local agents link`', $agentsSection);
        $this->assertStringContainsString('not generated activation links', $agentsSection);
    }

    public function test_compose_stub_uses_per_worktree_app_services_and_routing_labels(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );
        $compose = $this->contentFor($plan, 'docker-compose.yml');

        $this->assertStringContainsString('build:', $compose);
        $this->assertStringContainsString('node:24.11.1', $compose);
        $this->assertStringContainsString('profiles:', $compose);
        $this->assertStringContainsString('- frontend', $compose);
        $this->assertStringContainsString('${COMPOSE_PROJECT_NAME:-fresh-laravel-app}', $compose);
        $this->assertStringContainsString('context: ./docker/agent-ready/frankenphp', $compose);
        $this->assertStringContainsString('traefik.enable=true', $compose);
        $this->assertStringContainsString('traefik.docker.network=traefik-public', $compose);
        $this->assertStringContainsString('${AGENT_READY_APP_HOST:-fresh-laravel-app.localhost}', $compose);
        $this->assertStringContainsString('${AGENT_READY_VITE_HOST:-vite.fresh-laravel-app.localhost}', $compose);
        $this->assertStringContainsString('entrypoints=websecure', $compose);
        $this->assertStringContainsString('tls=true', $compose);
        $this->assertStringContainsString('shared-services:', $compose);
        $this->assertStringContainsString('name: shared-services', $compose);
        $this->assertStringContainsString('traefik-public:', $compose);
        $this->assertStringContainsString('external: true', $compose);
        $this->assertStringNotContainsString('horizon:', $compose);
    }

    public function test_existing_project_candidate_plan_only_contains_review_companion_compose(): void
    {
        $planner = new InstallPlanner;
        $frankenPlan = $planner->existingProjectCandidatesPlan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );
        $nginxPlan = $planner->existingProjectCandidatesPlan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(runtime: 'nginx-php-fpm', databaseEngine: 'mysql'),
        );
        $frankenCompose = $this->contentFor($frankenPlan, 'worktree.docker-compose.yml');
        $nginxCompose = $this->contentFor($nginxPlan, 'worktree.docker-compose.yml');

        $this->assertSame(['worktree.docker-compose.yml'], $frankenPlan->paths());
        $this->assertStringContainsString('Agent-Ready existing-project candidate companion file.', $frankenCompose);
        $this->assertStringContainsString('Review and manually integrate this file', $frankenCompose);
        $this->assertStringContainsString('It is not wired automatically', $frankenCompose);
        $this->assertStringContainsString('context: ./docker/agent-ready/frankenphp', $frankenCompose);
        $this->assertStringNotContainsString('nginx:1.27.4', $frankenCompose);
        $this->assertSame(['worktree.docker-compose.yml'], $nginxPlan->paths());
        $this->assertStringContainsString('context: ./docker/agent-ready/php-fpm', $nginxCompose);
        $this->assertStringContainsString('nginx:1.27.4', $nginxCompose);
        $this->assertStringNotContainsString('context: ./docker/agent-ready/frankenphp', $nginxCompose);
    }

    public function test_horizon_option_generates_worker_profile_and_persists_state(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(horizon: true),
        );
        $state = json_decode($this->contentFor($plan, '.agent-ready/worktree.json'), true, 512, JSON_THROW_ON_ERROR);
        $compose = $this->contentFor($plan, 'docker-compose.yml');

        $this->assertSame(true, $state['horizon']);
        $this->assertArrayNotHasKey('horizonPrefix', $state);
        $this->assertStringContainsString('  horizon:', $compose);
        $this->assertStringContainsString('context: ./docker/agent-ready/frankenphp', $compose);
        $this->assertStringContainsString('command: php artisan horizon', $compose);
        $this->assertStringContainsString('- horizon', $compose);
        $this->assertStringContainsString('HORIZON_PREFIX: ${HORIZON_PREFIX}', $compose);
    }

    public function test_shared_compose_file_is_not_generated_after_shared_services_extraction(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );

        $this->assertNotContains('docker-compose.shared.yml', $plan->paths());
    }

    public function test_frankenphp_dockerfile_uses_pinned_base_images(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );
        $dockerfile = $this->contentFor($plan, 'docker/agent-ready/frankenphp/Dockerfile');

        $this->assertStringContainsString('dunglas/frankenphp:1.4.0-php8.4', $dockerfile);
        $this->assertStringContainsString('composer:2.9.2', $dockerfile);
        $this->assertStringContainsString('libpq-dev', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-install pdo_pgsql', $dockerfile);
    }

    public function test_mysql_app_dockerfile_uses_pdo_mysql_without_pgsql_packages(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(databaseEngine: 'mysql'),
        );
        $dockerfile = $this->contentFor($plan, 'docker/agent-ready/frankenphp/Dockerfile');

        $this->assertStringContainsString('docker-php-ext-install pdo_mysql', $dockerfile);
        $this->assertStringNotContainsString('libpq-dev', $dockerfile);
        $this->assertStringNotContainsString('pdo_pgsql', $dockerfile);
    }

    public function test_nginx_php_fpm_runtime_generates_selected_runtime_support(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(runtime: 'nginx-php-fpm', databaseEngine: 'mysql'),
        );
        $paths = $plan->paths();
        $state = json_decode($this->contentFor($plan, '.agent-ready/worktree.json'), true, 512, JSON_THROW_ON_ERROR);
        $compose = $this->contentFor($plan, 'docker-compose.yml');
        $dockerfile = $this->contentFor($plan, 'docker/agent-ready/php-fpm/Dockerfile');

        $this->assertContains('docker/agent-ready/php-fpm/Dockerfile', $paths);
        $this->assertContains('docker/agent-ready/nginx/default.conf', $paths);
        $this->assertNotContains('docker/agent-ready/frankenphp/Dockerfile', $paths);
        $this->assertNotContains('docker/agent-ready/frankenphp/Caddyfile', $paths);
        $this->assertSame('nginx-php-fpm', $state['runtime']);
        $this->assertSame('mysql', $state['databaseEngine']);
        $this->assertStringContainsString('context: ./docker/agent-ready/php-fpm', $compose);
        $this->assertStringContainsString('nginx:1.27.4', $compose);
        $this->assertStringContainsString('shared-services:', $compose);
        $this->assertStringContainsString('traefik-public:', $compose);
        $this->assertStringContainsString('php:8.4.15-fpm', $dockerfile);
        $this->assertStringContainsString('composer:2.9.2', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-install pdo_mysql', $dockerfile);
        $this->assertStringContainsString('fastcgi_pass app:9000;', $this->contentFor($plan, 'docker/agent-ready/nginx/default.conf'));
    }

    public function test_nginx_php_fpm_runtime_can_generate_horizon_worker_profile(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(runtime: 'nginx-php-fpm', databaseEngine: 'mysql', horizon: true),
        );
        $state = json_decode($this->contentFor($plan, '.agent-ready/worktree.json'), true, 512, JSON_THROW_ON_ERROR);
        $compose = $this->contentFor($plan, 'docker-compose.yml');

        $this->assertSame(true, $state['horizon']);
        $this->assertStringContainsString('  horizon:', $compose);
        $this->assertStringContainsString('context: ./docker/agent-ready/php-fpm', $compose);
        $this->assertStringNotContainsString('context: ./docker/agent-ready/frankenphp', $compose);
        $this->assertStringContainsString('command: php artisan horizon', $compose);
        $this->assertStringContainsString('- horizon', $compose);
        $this->assertStringContainsString('HORIZON_PREFIX: ${HORIZON_PREFIX}', $compose);
    }

    public function test_opencode_example_keeps_boost_mcp_disabled_by_default(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );
        $opencode = $this->contentFor($plan, 'opencode.jsonc.example');

        $this->assertStringContainsString('"enabled": false', $opencode);
        $this->assertStringContainsString('["./local", "mcp", "boost"]', $opencode);
        $this->assertStringContainsString('"instructions": ["AGENTS.md"]', $opencode);
        $this->assertStringNotContainsString('"skill": [".agents/skills"]', $opencode);
    }

    public function test_local_wrapper_exposes_default_command_surface(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );
        $local = $this->contentFor($plan, 'tools/agent-ready/local.sh');
        $shim = $this->contentFor($plan, 'local');

        $this->assertStringContainsString('tools/agent-ready/local.sh', $shim);
        $this->assertStringContainsString('assets build', $local);
        $this->assertStringContainsString('npm ...', $local);
        $this->assertStringContainsString('compose_exec app php', $local);
        $this->assertStringContainsString('run --rm --no-deps vite npm', $local);
        $this->assertStringContainsString('mcp boost', $local);
        $this->assertStringContainsString('traefik ensure|status|prune', $local);
        $this->assertStringContainsString('worktree list|create|up|down|status|remove', $local);
        $this->assertStringContainsString('sail ...', $local);
        $this->assertStringNotContainsString('agents link', $local);
    }

    public function test_generated_shell_helpers_include_traefik_discovery_and_worktree_lifecycle(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );
        $common = $this->contentFor($plan, 'tools/agent-ready/lib/common.sh');
        $worktreesScript = $this->contentFor($plan, 'tools/agent-ready/lib/worktrees.sh');

        $this->assertStringContainsString('traefik_home', $common);
        $this->assertStringNotContainsString('AGENT_READY_TRAEFIK_HOME', $common);
        $this->assertStringContainsString('services_home', $common);
        $this->assertStringNotContainsString('AGENT_READY_SERVICES_HOME', $common);
        $this->assertStringContainsString('ensure_shared_services', $common);
        $this->assertStringContainsString('provision_shared_databases', $common);
        $this->assertStringContainsString('derive_shared_checkout_env', $common);
        $this->assertStringContainsString('materialize_runtime_env', $common);
        $this->assertStringContainsString('cleanup_shared_databases', $common);
        $this->assertStringContainsString('cleanup_shared_valkey_namespaces', $common);
        $this->assertStringContainsString('cleanup_shared_horizon_namespaces', $common);
        $this->assertStringContainsString('bin/ensure" --database="$AGENT_READY_DATABASE_ENGINE" --cache=valkey', $common);
        $this->assertStringContainsString('bin/provision-checkout"', $common);
        $this->assertStringContainsString('bin/derive-checkout"', $common);
        $this->assertStringContainsString('bin/drop-checkout"', $common);
        $this->assertStringContainsString('bin/clean-prefix"', $common);
        $this->assertStringContainsString('unset HORIZON_PREFIX', $common);
        $this->assertStringContainsString('derive-checkout did not provide HORIZON_PREFIX', $common);
        $this->assertStringNotContainsString('HORIZON_PREFIX="horizon:', $common);
        $this->assertStringContainsString('COMPOSE_PROJECT_NAME="$compose_project"', $common);
        $this->assertStringNotContainsString('docker-compose.shared.yml', $common);
        $this->assertStringContainsString('tty_args=(-T)', $common);
        $this->assertStringContainsString('create_worktree', $worktreesScript);
        $this->assertStringContainsString('remove_worktree', $worktreesScript);
        $this->assertStringContainsString('write_worktrees_index', $worktreesScript);
    }

    public function test_agent_guidance_excludes_optional_conventions_by_default(): void
    {
        $agents = (new InstallPlanner)->agentsSection($this->installOptions());

        $this->assertStringContainsString('Horizon prefixes apply only when Horizon is enabled.', $agents);
        $this->assertStringNotContainsString('.ai/skills', $agents);
        $this->assertStringNotContainsString('.agents/skills', $agents);
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );

        $this->assertStringNotContainsString('./local agents link', $this->contentFor($plan, 'tools/agent-ready/lib/worktrees.sh'));
    }

    public function test_rendered_generated_operation_contents_have_no_unresolved_tokens(): void
    {
        $plans = [
            (new InstallPlanner)->plan(
                ProjectContext::fromPath($this->makeProjectRoot()),
                $this->installOptions(
                    decisionNotes: true,
                    localAgentNotes: true,
                    agentSkills: true,
                    traefikHome: '/home/example/shared-traefik',
                    servicesHome: '/home/example/shared-services',
                ),
            ),
            (new InstallPlanner)->plan(
                ProjectContext::fromPath($this->makeProjectRoot()),
                $this->installOptions(runtime: 'nginx-php-fpm', databaseEngine: 'mysql'),
            ),
            (new InstallPlanner)->plan(
                ProjectContext::fromPath($this->makeProjectRoot()),
                $this->installOptions(horizon: true),
            ),
        ];

        foreach ($plans as $plan) {
            foreach ($plan->operations() as $operation) {
                if ($operation instanceof WriteFileOperation) {
                    $this->assertDoesNotMatchRegularExpression('/{{\s*[A-Za-z0-9_]+\s*}}/', $operation->content, $operation->path);
                }

                if ($operation instanceof UpsertMarkedBlockOperation) {
                    $this->assertDoesNotMatchRegularExpression('/{{\s*[A-Za-z0-9_]+\s*}}/', $operation->body, $operation->path);
                }
            }
        }
    }

    public function test_generated_shell_files_are_valid_bash(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(),
        );
        $paths = [
            'local',
            'tools/agent-ready/local.sh',
            'tools/agent-ready/lib/common.sh',
            'tools/agent-ready/lib/worktrees.sh',
        ];

        foreach ($paths as $generatedPath) {
            $path = $this->temporaryPath('agent-ready-shell-');
            file_put_contents($path, $this->contentFor($plan, $generatedPath));

            $output = [];
            $exitCode = 0;
            exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, $generatedPath."\n".implode("\n", $output));
        }
    }

    public function test_worktree_state_contains_canonical_lifecycle_identity(): void
    {
        $root = $this->makeProjectRoot();
        $plan = (new InstallPlanner)->plan(ProjectContext::fromPath($root), new InstallOptions(hostRoot: $root));

        $state = json_decode($this->contentFor($plan, '.agent-ready/worktree.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $state['version']);
        $this->assertSame($root, $state['root']);
        $this->assertSame($root, $state['mainWorktree']);
        $this->assertSame('fresh-laravel-app', $state['projectId']);
        $this->assertSame('fresh-laravel-app', $state['worktreeId']);
        $this->assertSame('frankenphp', $state['runtime']);
        $this->assertSame('pgsql', $state['databaseEngine']);
        $this->assertSame(false, $state['horizon']);
        $this->assertSame('fresh-laravel-app.localhost', $state['routeDomain']);
        $this->assertArrayNotHasKey('composeProject', $state);
        $this->assertArrayNotHasKey('sharedComposeProject', $state);
        $this->assertArrayNotHasKey('sharedNetwork', $state);
        $this->assertArrayNotHasKey('appDatabase', $state);
        $this->assertArrayNotHasKey('testDatabase', $state);
        $this->assertArrayNotHasKey('valkeyPrefix', $state);
        $this->assertArrayNotHasKey('horizonPrefix', $state);
        $this->assertArrayNotHasKey('appHost', $state);
        $this->assertArrayNotHasKey('viteHost', $state);
    }

    public function test_main_worktree_index_contains_the_initial_checkout(): void
    {
        $root = $this->makeProjectRoot();
        $plan = (new InstallPlanner)->plan(ProjectContext::fromPath($root), new InstallOptions(hostRoot: $root));

        $index = json_decode($this->contentFor($plan, '.agent-ready/worktrees.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $index['version']);
        $this->assertSame('fresh-laravel-app', $index['worktrees'][0]['id']);
        $this->assertSame($root, $index['worktrees'][0]['path']);
        $this->assertSame('fresh-laravel-app.localhost', $index['worktrees'][0]['appHost']);
    }

    public function test_generated_paths_can_be_customized(): void
    {
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            $this->installOptions(toolsPath: 'scripts/local-dev', dockerPath: 'infra/docker'),
        );

        $paths = $plan->paths();

        $this->assertContains('scripts/local-dev/local.sh', $paths);
        $this->assertContains('scripts/local-dev/lib/common.sh', $paths);
        $this->assertContains('infra/docker/frankenphp/Dockerfile', $paths);
        $this->assertContains('infra/docker/frankenphp/Caddyfile', $paths);
        $this->assertStringContainsString('scripts/local-dev/local.sh', $this->contentFor($plan, 'local'));
        $this->assertStringContainsString('context: ./infra/docker/frankenphp', $this->contentFor($plan, 'docker-compose.yml'));
    }

    public function test_default_laravel_skeleton_name_uses_directory_slug(): void
    {
        $root = $this->makeProjectRoot('laravel/laravel');
        $plan = (new InstallPlanner)->plan(ProjectContext::fromPath($root), new InstallOptions(hostRoot: $root));

        $state = json_decode($this->contentFor($plan, '.agent-ready/worktree.json'), true, 512, JSON_THROW_ON_ERROR);

        $expected = basename($root);

        $this->assertSame($expected, $state['projectId']);
        $this->assertSame($expected, $state['worktreeId']);
        $this->assertSame($expected.'.localhost', $state['routeDomain']);
    }

    public function test_host_root_override_is_used_for_persisted_worktree_paths(): void
    {
        $root = $this->makeProjectRoot();
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($root),
            new InstallOptions(hostRoot: '/home/example/app'),
        );

        $state = json_decode($this->contentFor($plan, '.agent-ready/worktree.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('/home/example/app', $state['root']);
        $this->assertSame('/home/example/app', $state['mainWorktree']);
    }

    public function test_route_domain_can_be_customized(): void
    {
        $root = $this->makeProjectRoot();
        $plan = (new InstallPlanner)->plan(
            ProjectContext::fromPath($root),
            new InstallOptions(
                hostRoot: '/home/example/app',
                routeDomain: 'project.localhost',
            ),
        );

        $state = json_decode($this->contentFor($plan, '.agent-ready/worktree.json'), true, 512, JSON_THROW_ON_ERROR);
        $index = json_decode($this->contentFor($plan, '.agent-ready/worktrees.json'), true, 512, JSON_THROW_ON_ERROR);
        $compose = $this->contentFor($plan, 'docker-compose.yml');

        $this->assertSame('project.localhost', $state['routeDomain']);
        $this->assertArrayNotHasKey('appHost', $state);
        $this->assertArrayNotHasKey('viteHost', $state);
        $this->assertSame('project.localhost', $index['worktrees'][0]['appHost']);
        $this->assertStringContainsString('${AGENT_READY_APP_HOST:-project.localhost}', $compose);
        $this->assertStringContainsString('${AGENT_READY_VITE_HOST:-vite.project.localhost}', $compose);
    }

    private function contentFor(InstallationPlan $plan, string $path): string
    {
        return $this->writeOperationFor($plan, $path)->content;
    }

    private function writeOperationFor(InstallationPlan $plan, string $path): WriteFileOperation
    {
        $operation = $plan->operationFor($path);

        $this->assertInstanceOf(WriteFileOperation::class, $operation);

        return $operation;
    }

    private function installOptions(
        bool $decisionNotes = false,
        bool $localAgentNotes = false,
        bool $agentSkills = false,
        string $toolsPath = 'tools/agent-ready',
        string $dockerPath = 'docker/agent-ready',
        ?string $traefikHome = null,
        ?string $servicesHome = null,
        ?string $routeDomain = null,
        string $runtime = 'frankenphp',
        string $databaseEngine = 'pgsql',
        bool $horizon = false,
    ): InstallOptions {
        return new InstallOptions(
            hostRoot: '/home/example/app',
            decisionNotes: $decisionNotes,
            localAgentNotes: $localAgentNotes,
            agentSkills: $agentSkills,
            toolsPath: $toolsPath,
            dockerPath: $dockerPath,
            traefikHome: $traefikHome,
            servicesHome: $servicesHome,
            routeDomain: $routeDomain,
            runtime: $runtime,
            databaseEngine: $databaseEngine,
            horizon: $horizon,
        );
    }

    private function makeProjectRoot(string $name = 'example/fresh-laravel-app'): string
    {
        $root = $this->temporaryDirectory('agent-ready-installer-test-');

        $this->writeLaravelComposerJson($root, $name);
        file_put_contents($root.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\n");

        return $root;
    }
}
