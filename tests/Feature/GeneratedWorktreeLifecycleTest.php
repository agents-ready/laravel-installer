<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests\Feature;

use AgentsReady\LaravelInstaller\Install\InstallOptions;
use AgentsReady\LaravelInstaller\Install\InstallPlanner;
use AgentsReady\LaravelInstaller\Install\Plan\InstallPlanApplier;
use AgentsReady\LaravelInstaller\Install\ProjectContext;
use AgentsReady\LaravelInstaller\Install\Support\TextFilePatcher;
use AgentsReady\LaravelInstaller\Tests\UnitTestCase;
use Symfony\Component\Process\Process;

final class GeneratedWorktreeLifecycleTest extends UnitTestCase
{
    public function test_worktree_commands_fail_without_git_before_mutating_state(): void
    {
        $root = $this->makeGeneratedProject();

        $indexBefore = (string) file_get_contents($root.'/.agents-ready/worktrees.json');
        $output = $this->runFailingIn($root, ['./local', 'worktree', 'list']);

        $this->assertStringContainsString('requires this project to be a Git repository', $output);
        $this->assertStringNotContainsString('awk:', $output);
        $this->assertSame($indexBefore, (string) file_get_contents($root.'/.agents-ready/worktrees.json'));
    }

    public function test_create_list_and_remove_lifecycle_without_starting_docker(): void
    {
        $root = $this->makeCommittedGeneratedProject();
        $target = $this->temporaryPath('agents-ready-generated-worktree-');
        $fakeDockerBin = $this->fakeUnavailableDockerBin();

        $this->runIn($root, ['./local', 'worktree', 'create', $target, '--branch', 'feature/generated', '--id', 'generated']);

        $state = json_decode((string) file_get_contents($target.'/.agents-ready/worktree.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('generated', $state['worktreeId']);
        $this->assertSame($root, $state['mainWorktree']);
        $this->assertSame('fresh-laravel-app', $state['projectId']);
        $this->assertSame('frankenphp', $state['runtime']);
        $this->assertSame('pgsql', $state['databaseEngine']);
        $this->assertSame(false, $state['horizon']);
        $this->assertSame('fresh-laravel-app.localhost', $state['routeDomain']);
        $this->assertArrayNotHasKey('appDatabase', $state);
        $this->assertArrayNotHasKey('valkeyPrefix', $state);
        $this->assertFileDoesNotExist($target.'/.env');
        $this->assertFileIsReadable($target.'/tools/agents-ready/skills/worktree-lifecycle/SKILL.md');

        $indexAfterCreate = json_decode((string) file_get_contents($root.'/.agents-ready/worktrees.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(2, $indexAfterCreate['worktrees']);
        $this->assertSame(['fresh-laravel-app', 'generated'], array_column($indexAfterCreate['worktrees'], 'id'));
        $this->assertSame($target, $indexAfterCreate['worktrees'][1]['path']);
        $this->assertSame('feature/generated', $indexAfterCreate['worktrees'][1]['branch']);
        $this->assertSame('generated.fresh-laravel-app.localhost', $indexAfterCreate['worktrees'][1]['appHost']);

        $listOutput = $this->runIn($root, ['./local', 'worktree', 'list']);

        $this->assertStringContainsString('generated', $listOutput);
        $this->assertStringContainsString($target, $listOutput);

        $this->runIn($root, ['./local', 'worktree', 'remove', 'generated', '--force'], [
            'PATH' => $fakeDockerBin.PATH_SEPARATOR.(string) getenv('PATH'),
        ]);

        $this->assertDirectoryDoesNotExist($target);

        $index = json_decode((string) file_get_contents($root.'/.agents-ready/worktrees.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $index['worktrees']);
        $this->assertSame('fresh-laravel-app', $index['worktrees'][0]['id']);
    }

    public function test_worktree_create_inherits_runtime_database_and_horizon_from_main_state(): void
    {
        $root = $this->makeCommittedGeneratedProject(runtime: 'nginx-php-fpm', databaseEngine: 'mysql', horizon: true);
        $target = $this->temporaryPath('agents-ready-generated-worktree-');

        $this->runIn($root, ['./local', 'worktree', 'create', $target, '--branch', 'feature/inherit-runtime', '--id', 'runtime-child']);

        $state = json_decode((string) file_get_contents($target.'/.agents-ready/worktree.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('nginx-php-fpm', $state['runtime']);
        $this->assertSame('mysql', $state['databaseEngine']);
        $this->assertSame(true, $state['horizon']);
    }

    public function test_worktree_hosts_derive_from_custom_route_domain(): void
    {
        $root = $this->makeCommittedGeneratedProject(routeDomain: 'project.localhost');
        $target = $this->temporaryPath('agents-ready-generated-worktree-');

        $this->runIn($root, ['./local', 'worktree', 'create', $target, '--branch', 'feature/custom-host', '--id', 'custom']);

        $state = json_decode((string) file_get_contents($target.'/.agents-ready/worktree.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('project.localhost', $state['routeDomain']);
        $this->assertArrayNotHasKey('appHost', $state);
        $this->assertArrayNotHasKey('viteHost', $state);

        $index = json_decode((string) file_get_contents($root.'/.agents-ready/worktrees.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('custom.project.localhost', $index['worktrees'][1]['appHost']);
    }

    public function test_local_up_delegates_to_shared_services_and_materializes_env_without_identity_keys(): void
    {
        $root = $this->makeGeneratedProject();
        $log = $this->temporaryPath('agents-ready-shared-services-log-');
        $traefik = $this->fakeTraefikHome($log);
        $services = $this->fakeServicesHome($log);
        $fakeBin = $this->fakeDockerBin($log);

        if (! is_dir($root.'/.agents-ready')) {
            mkdir($root.'/.agents-ready', 0777, true);
        }
        file_put_contents($root.'/.agents-ready/config.json', json_encode([
            'version' => 1,
            'traefikHome' => $traefik,
            'servicesHome' => $services,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        file_put_contents($root.'/.env', "APP_KEY=base64:smoke-key\nCUSTOM_SECRET=keep-me\nDB_CONNECTION=sqlite\n");

        $this->runIn($root, ['./local', 'up'], ['PATH' => $fakeBin.':'.((string) getenv('PATH'))]);

        $env = (string) file_get_contents($root.'/.env');
        $testingEnv = (string) file_get_contents($root.'/.env.testing');
        $calls = (string) file_get_contents($log);

        $this->assertStringContainsString('traefik ensure', $calls);
        $this->assertStringContainsString('services ensure --database=pgsql --cache=valkey', $calls);
        $this->assertStringContainsString('services provision --project-id=fresh-laravel-app --worktree-id=fresh-laravel-app --database=pgsql --root='.$root, $calls);
        $this->assertStringContainsString('services derive --project-id=fresh-laravel-app --worktree-id=fresh-laravel-app --database=pgsql --format=env', $calls);
        $this->assertStringContainsString('docker compose --file docker-compose.yml up -d', $calls);
        $this->assertStringContainsString('APP_KEY=base64:smoke-key', $env);
        $this->assertStringContainsString('CUSTOM_SECRET=keep-me', $env);
        $this->assertStringContainsString('APP_URL=https://fresh-laravel-app.localhost', $env);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $env);
        $this->assertStringContainsString('DB_DATABASE=fresh_laravel_app', $env);
        $this->assertStringContainsString('REDIS_PREFIX=fresh-laravel-app:', $env);
        $this->assertStringNotContainsString('HORIZON_PREFIX=', $env);
        $this->assertStringContainsString('COMPOSE_PROJECT_NAME=fresh_laravel_app', $env);
        $this->assertStringNotContainsString('PROJECT_ID=', $env);
        $this->assertStringNotContainsString('WORKTREE_ID=', $env);
        $this->assertStringContainsString('APP_KEY=base64:smoke-key', $testingEnv);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', $testingEnv);
        $this->assertStringContainsString('DB_DATABASE=fresh_laravel_app_test', $testingEnv);
        $this->assertStringNotContainsString('HORIZON_PREFIX=', $testingEnv);
        $this->assertStringNotContainsString('PROJECT_ID=', $testingEnv);
        $this->assertStringNotContainsString('WORKTREE_ID=', $testingEnv);
    }

    public function test_local_up_materializes_mysql_env_from_shared_services(): void
    {
        $root = $this->makeGeneratedProject(databaseEngine: 'mysql');
        $log = $this->temporaryPath('agents-ready-shared-services-log-');
        $traefik = $this->fakeTraefikHome($log);
        $services = $this->fakeServicesHome($log, databaseEngine: 'mysql');
        $fakeBin = $this->fakeDockerBin($log);

        if (! is_dir($root.'/.agents-ready')) {
            mkdir($root.'/.agents-ready', 0777, true);
        }
        file_put_contents($root.'/.agents-ready/config.json', json_encode([
            'version' => 1,
            'traefikHome' => $traefik,
            'servicesHome' => $services,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        file_put_contents($root.'/.env', "APP_KEY=base64:smoke-key\nDB_CONNECTION=pgsql\n");

        $this->runIn($root, ['./local', 'up'], ['PATH' => $fakeBin.':'.((string) getenv('PATH'))]);

        $env = (string) file_get_contents($root.'/.env');
        $testingEnv = (string) file_get_contents($root.'/.env.testing');
        $calls = (string) file_get_contents($log);

        $this->assertStringContainsString('services ensure --database=mysql --cache=valkey', $calls);
        $this->assertStringContainsString('services provision --project-id=fresh-laravel-app --worktree-id=fresh-laravel-app --database=mysql --root='.$root, $calls);
        $this->assertStringContainsString('services derive --project-id=fresh-laravel-app --worktree-id=fresh-laravel-app --database=mysql --format=env', $calls);
        $this->assertStringContainsString('DB_CONNECTION=mysql', $env);
        $this->assertStringContainsString('DB_HOST=mysql', $env);
        $this->assertStringContainsString('DB_PORT=3306', $env);
        $this->assertStringContainsString('DB_DATABASE=fresh_laravel_app', $env);
        $this->assertStringContainsString('DB_CONNECTION=mysql', $testingEnv);
        $this->assertStringContainsString('DB_HOST=mysql', $testingEnv);
        $this->assertStringContainsString('DB_PORT=3306', $testingEnv);
        $this->assertStringContainsString('DB_DATABASE=fresh_laravel_app_test', $testingEnv);
    }

    public function test_local_up_materializes_horizon_prefix_when_enabled(): void
    {
        $root = $this->makeGeneratedProject(horizon: true);
        $log = $this->temporaryPath('agents-ready-shared-services-log-');
        $horizonPrefix = 'shared-services:horizon:sentinel:';
        $traefik = $this->fakeTraefikHome($log);
        $services = $this->fakeServicesHome($log, horizonPrefix: $horizonPrefix);
        $fakeBin = $this->fakeDockerBin($log);

        if (! is_dir($root.'/.agents-ready')) {
            mkdir($root.'/.agents-ready', 0777, true);
        }
        file_put_contents($root.'/.agents-ready/config.json', json_encode([
            'version' => 1,
            'traefikHome' => $traefik,
            'servicesHome' => $services,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->runIn($root, ['./local', 'up'], ['PATH' => $fakeBin.':'.((string) getenv('PATH'))]);

        $env = (string) file_get_contents($root.'/.env');
        $testingEnv = (string) file_get_contents($root.'/.env.testing');

        $this->assertStringContainsString("HORIZON_PREFIX={$horizonPrefix}\n", $env);
        $this->assertStringContainsString("HORIZON_PREFIX={$horizonPrefix}\n", $testingEnv);
        $this->assertStringNotContainsString('HORIZON_PREFIX=horizon:fresh-laravel-app:', $env);
    }

    public function test_local_up_fails_when_horizon_enabled_and_shared_services_omits_horizon_prefix(): void
    {
        $root = $this->makeGeneratedProject(horizon: true);
        $log = $this->temporaryPath('agents-ready-shared-services-log-');
        $traefik = $this->fakeTraefikHome($log);
        $services = $this->fakeServicesHome($log, horizonPrefix: null);
        $fakeBin = $this->fakeDockerBin($log);

        if (! is_dir($root.'/.agents-ready')) {
            mkdir($root.'/.agents-ready', 0777, true);
        }
        file_put_contents($root.'/.agents-ready/config.json', json_encode([
            'version' => 1,
            'traefikHome' => $traefik,
            'servicesHome' => $services,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $output = $this->runFailingIn($root, ['./local', 'up'], ['PATH' => $fakeBin.':'.((string) getenv('PATH'))]);

        $this->assertStringContainsString('Horizon is enabled, but shared-services derive-checkout did not provide HORIZON_PREFIX.', $output);
        $this->assertFileDoesNotExist($root.'/.env');
    }

    public function test_worktree_remove_cleans_valkey_namespace_without_horizon_when_disabled(): void
    {
        $root = $this->makeCommittedGeneratedProject();
        $target = $this->temporaryPath('agents-ready-generated-worktree-');
        $log = $this->temporaryPath('agents-ready-shared-services-log-');
        $services = $this->fakeServicesHome($log);
        $fakeBin = $this->fakeDockerBin($log);

        if (! is_dir($root.'/.agents-ready')) {
            mkdir($root.'/.agents-ready', 0777, true);
        }
        file_put_contents($root.'/.agents-ready/config.json', json_encode([
            'version' => 1,
            'servicesHome' => $services,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->runIn($root, ['./local', 'worktree', 'create', $target, '--branch', 'feature/cleanup-valkey', '--id', 'cleanup-valkey']);
        $this->runIn($root, ['./local', 'worktree', 'remove', 'cleanup-valkey', '--force'], ['PATH' => $fakeBin.':'.((string) getenv('PATH'))]);

        $calls = (string) file_get_contents($log);

        $this->assertStringContainsString('services clean --project-id=fresh-laravel-app --worktree-id=cleanup-valkey --kind=valkey', $calls);
        $this->assertStringNotContainsString('--kind=horizon', $calls);
        $this->assertDirectoryDoesNotExist($target);
    }

    public function test_worktree_remove_cleans_horizon_namespace_only_when_horizon_is_enabled(): void
    {
        $root = $this->makeCommittedGeneratedProject(horizon: true);
        $target = $this->temporaryPath('agents-ready-generated-worktree-');
        $log = $this->temporaryPath('agents-ready-shared-services-log-');
        $services = $this->fakeServicesHome($log);
        $fakeBin = $this->fakeDockerBin($log);

        if (! is_dir($root.'/.agents-ready')) {
            mkdir($root.'/.agents-ready', 0777, true);
        }
        file_put_contents($root.'/.agents-ready/config.json', json_encode([
            'version' => 1,
            'servicesHome' => $services,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->runIn($root, ['./local', 'worktree', 'create', $target, '--branch', 'feature/cleanup-horizon', '--id', 'cleanup-horizon']);
        $this->runIn($root, ['./local', 'worktree', 'remove', 'cleanup-horizon', '--force'], ['PATH' => $fakeBin.':'.((string) getenv('PATH'))]);

        $calls = (string) file_get_contents($log);

        $this->assertStringContainsString('services clean --project-id=fresh-laravel-app --worktree-id=cleanup-horizon --kind=valkey', $calls);
        $this->assertStringContainsString('services clean --project-id=fresh-laravel-app --worktree-id=cleanup-horizon --kind=horizon', $calls);
        $this->assertDirectoryDoesNotExist($target);
    }

    public function test_worktree_create_refuses_duplicate_ids(): void
    {
        $root = $this->makeCommittedGeneratedProject();
        $firstTarget = $this->temporaryPath('agents-ready-generated-worktree-');
        $secondTarget = $this->temporaryPath('agents-ready-generated-worktree-');

        $this->runIn($root, ['./local', 'worktree', 'create', $firstTarget, '--branch', 'feature/generated-one', '--id', 'generated']);
        $output = $this->runFailingIn($root, ['./local', 'worktree', 'create', $secondTarget, '--branch', 'feature/generated-two', '--id', 'generated']);

        $this->assertStringContainsString('Refusing to create a managed worktree because the ID already exists: generated', $output);
        $this->assertDirectoryDoesNotExist($secondTarget);
    }

    public function test_worktree_create_refuses_existing_branches(): void
    {
        $root = $this->makeCommittedGeneratedProject();
        $target = $this->temporaryPath('agents-ready-generated-worktree-');

        $this->runIn($root, ['git', 'branch', 'feature/existing']);
        $output = $this->runFailingIn($root, ['./local', 'worktree', 'create', $target, '--branch', 'feature/existing', '--id', 'existing']);

        $this->assertStringContainsString('Refusing to create a managed worktree because the branch already exists: feature/existing', $output);
        $this->assertDirectoryDoesNotExist($target);
    }

    public function test_worktree_remove_refuses_dirty_worktrees_without_force(): void
    {
        $root = $this->makeCommittedGeneratedProject();
        $target = $this->temporaryPath('agents-ready-generated-worktree-');

        $this->runIn($root, ['./local', 'worktree', 'create', $target, '--branch', 'feature/dirty', '--id', 'dirty']);
        file_put_contents($target.'/dirty.txt', "dirty\n");

        $output = $this->runFailingIn($root, ['./local', 'worktree', 'remove', 'dirty']);

        $this->assertStringContainsString('Refusing to remove a worktree with tracked or untracked changes', $output);
        $this->assertDirectoryExists($target);
    }

    public function test_worktree_remove_refuses_the_main_worktree(): void
    {
        $root = $this->makeCommittedGeneratedProject();

        $output = $this->runFailingIn($root, ['./local', 'worktree', 'remove']);

        $this->assertStringContainsString('Refusing to remove the main worktree', $output);
        $this->assertDirectoryExists($root);
    }

    public function test_worktree_remove_refuses_unmanaged_directories(): void
    {
        $root = $this->makeCommittedGeneratedProject();
        $target = $this->temporaryDirectory('agents-ready-unmanaged-worktree-');

        $output = $this->runFailingIn($root, ['./local', 'worktree', 'remove', $target, '--force']);

        $this->assertStringContainsString('Refusing to remove an unmanaged directory', $output);
        $this->assertDirectoryExists($target);
    }

    public function test_worktree_create_rolls_back_when_generated_wrapper_is_not_committed_as_executable(): void
    {
        $root = $this->makeCommittedGeneratedProject();
        $target = $this->temporaryPath('agents-ready-generated-worktree-');

        chmod($root.'/local', 0644);
        $this->runIn($root, ['git', 'add', 'local']);
        $this->runIn($root, [
            'git',
            '-c',
            'user.name=Agents Ready',
            '-c',
            'user.email=agents-ready@example.test',
            'commit',
            '-m',
            'Make local wrapper non-executable',
        ]);

        $output = $this->runFailingIn($root, ['bash', './local', 'worktree', 'create', $target, '--branch', 'feature/non-executable', '--id', 'non-executable']);

        $this->assertStringContainsString('The new worktree does not contain ./local.', $output);
        $this->assertStringContainsString('Rolling back failed managed worktree creation', $output);
        $this->assertDirectoryDoesNotExist($target);
        $this->assertStringNotContainsString('feature/non-executable', $this->runIn($root, ['git', 'branch', '--list', 'feature/non-executable']));
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     */
    private function runIn(string $cwd, array $command, array $env = []): string
    {
        $process = new Process($command, $cwd);
        if ($env !== []) {
            $process->setEnv($env);
        }
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());

        return $process->getOutput().$process->getErrorOutput();
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     */
    private function runFailingIn(string $cwd, array $command, array $env = []): string
    {
        $process = new Process($command, $cwd);
        if ($env !== []) {
            $process->setEnv($env);
        }
        $process->setTimeout(30);
        $process->run();

        $this->assertFalse($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());

        return $process->getOutput().$process->getErrorOutput();
    }

    private function makeCommittedGeneratedProject(?string $routeDomain = null, string $runtime = 'frankenphp', string $databaseEngine = 'pgsql', bool $horizon = false): string
    {
        $root = $this->makeGeneratedProject(routeDomain: $routeDomain, runtime: $runtime, databaseEngine: $databaseEngine, horizon: $horizon);

        $this->runIn($root, ['git', 'init']);
        $this->runIn($root, ['git', 'add', '.']);
        $this->runIn($root, [
            'git',
            '-c',
            'user.name=Agents Ready',
            '-c',
            'user.email=agents-ready@example.test',
            'commit',
            '-m',
            'Initial Agents-Ready files',
        ]);

        return $root;
    }

    private function makeGeneratedProject(?string $routeDomain = null, string $runtime = 'frankenphp', string $databaseEngine = 'pgsql', bool $horizon = false): string
    {
        $root = $this->makeProjectRoot();
        $planner = new InstallPlanner;
        $context = ProjectContext::fromPath($root);
        $options = new InstallOptions(hostRoot: $root, routeDomain: $routeDomain, runtime: $runtime, databaseEngine: $databaseEngine, horizon: $horizon);

        (new InstallPlanApplier(new TextFilePatcher))->apply($root, $planner->plan($context, $options));

        return $root;
    }

    private function makeProjectRoot(): string
    {
        $root = $this->temporaryDirectory('agents-ready-installer-test-');

        $this->writeLaravelComposerJson($root);

        return $root;
    }

    private function fakeTraefikHome(string $log): string
    {
        $root = $this->temporaryDirectory('agents-ready-fake-traefik-');

        mkdir($root.'/bin', 0777, true);
        file_put_contents($root.'/bin/ensure', "#!/usr/bin/env bash\nprintf 'traefik ensure %s\\n' \"\$*\" >> ".escapeshellarg($log)."\n");
        chmod($root.'/bin/ensure', 0755);

        return $root;
    }

    private function fakeServicesHome(string $log, string $databaseEngine = 'pgsql', ?string $horizonPrefix = 'shared-services:horizon:sentinel:'): string
    {
        $root = $this->temporaryDirectory('agents-ready-fake-services-');
        $dbHost = $databaseEngine === 'mysql' ? 'mysql' : 'postgres';
        $dbPort = $databaseEngine === 'mysql' ? '3306' : '5432';
        $deriveEnvLines = [
            'PROJECT_ID=fresh-laravel-app',
            'WORKTREE_ID=fresh-laravel-app',
            'DATABASE_ENGINE='.$databaseEngine,
            'DB_CONNECTION='.$databaseEngine,
            'DB_HOST='.$dbHost,
            'DB_PORT='.$dbPort,
            'DB_DATABASE=fresh_laravel_app',
            'DB_TEST_DATABASE=fresh_laravel_app_test',
            'DB_USERNAME=app',
            'DB_PASSWORD=secret',
            'REDIS_HOST=valkey',
            'REDIS_PREFIX=fresh-laravel-app:',
            'COMPOSE_PROJECT_NAME=fresh_laravel_app',
            'APP_SERVICE=app',
        ];

        if ($horizonPrefix !== null) {
            $deriveEnvLines[] = 'HORIZON_PREFIX='.$horizonPrefix;
        }

        $deriveEnv = implode("\n", $deriveEnvLines)."\n";

        mkdir($root.'/bin', 0777, true);
        file_put_contents($root.'/bin/ensure', "#!/usr/bin/env bash\nprintf 'services ensure %s\\n' \"\$*\" >> ".escapeshellarg($log)."\n");
        file_put_contents($root.'/bin/provision-checkout', "#!/usr/bin/env bash\nprintf 'services provision %s\\n' \"\$*\" >> ".escapeshellarg($log)."\n");
        file_put_contents($root.'/bin/derive-checkout', "#!/usr/bin/env bash\nprintf 'services derive %s\\n' \"\$*\" >> ".escapeshellarg($log)."\ncat <<'ENV'\n".$deriveEnv."ENV\n");
        file_put_contents($root.'/bin/drop-checkout', "#!/usr/bin/env bash\nprintf 'services drop %s\\n' \"\$*\" >> ".escapeshellarg($log)."\n");
        file_put_contents($root.'/bin/clean-prefix', "#!/usr/bin/env bash\nprintf 'services clean %s\\n' \"\$*\" >> ".escapeshellarg($log)."\n");

        foreach (['ensure', 'provision-checkout', 'derive-checkout', 'drop-checkout', 'clean-prefix'] as $script) {
            chmod($root.'/bin/'.$script, 0755);
        }

        return $root;
    }

    private function fakeDockerBin(string $log): string
    {
        $root = $this->temporaryDirectory('agents-ready-fake-docker-bin-');

        file_put_contents($root.'/docker', "#!/usr/bin/env bash\nprintf 'docker %s\\n' \"\$*\" >> ".escapeshellarg($log)."\nexit 0\n");
        chmod($root.'/docker', 0755);

        return $root;
    }

    private function fakeUnavailableDockerBin(): string
    {
        $root = $this->temporaryDirectory('agents-ready-fake-unavailable-docker-bin-');

        file_put_contents($root.'/docker', "#!/usr/bin/env bash\nexit 1\n");
        chmod($root.'/docker', 0755);

        return $root;
    }
}
