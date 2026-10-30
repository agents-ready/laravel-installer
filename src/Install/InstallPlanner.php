<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install;

use AgentsReady\LaravelInstaller\Install\Operations\AppendMissingLinesOperation;
use AgentsReady\LaravelInstaller\Install\Operations\UpsertMarkedBlockOperation;
use AgentsReady\LaravelInstaller\Install\Operations\WriteFileOperation;
use AgentsReady\LaravelInstaller\Install\Plan\InstallationPlan;
use AgentsReady\LaravelInstaller\Install\Support\StubRenderer;
use InvalidArgumentException;

final class InstallPlanner
{
    private readonly StubRenderer $stubs;

    private readonly GeneratedTooling $tooling;

    private readonly AgentsGuidance $agentsGuidance;

    public function __construct(?StubRenderer $stubs = null)
    {
        $this->stubs = $stubs ?? new StubRenderer;
        $this->tooling = new GeneratedTooling($this->stubs);
        $this->agentsGuidance = new AgentsGuidance($this->stubs);
    }

    public function plan(ProjectContext $context, InstallOptions $options): InstallationPlan
    {
        $this->assertSupportedOptions($options);

        $slug = $this->projectSlug($context, $options);
        $toolsPath = $options->toolsPath;
        $dockerPath = $options->dockerPath;
        $routeDomain = $options->routeDomain ?? $slug.'.localhost';
        $appHost = $this->appHost($routeDomain);
        $viteHost = $this->viteHost($routeDomain);

        $operations = [
            WriteFileOperation::conflictIfDifferent(AgentsReadyPath::LOCAL, $this->tooling->localWrapper($toolsPath), 0755),
            WriteFileOperation::conflictIfDifferent(AgentsReadyPath::localDispatcher($toolsPath), $this->tooling->localDispatcher(), 0755),
            WriteFileOperation::conflictIfDifferent(AgentsReadyPath::commonLibrary($toolsPath), $this->tooling->commonShellLibrary()),
            WriteFileOperation::conflictIfDifferent(AgentsReadyPath::worktreesLibrary($toolsPath), $this->tooling->worktreesShellLibrary()),
            WriteFileOperation::conflictIfDifferent(AgentsReadyPath::coreWorktreeSkill($toolsPath), $this->worktreeSkill()),
            WriteFileOperation::conflictIfDifferent(AgentsReadyPath::COMPOSE, $this->compose($slug, $dockerPath, $appHost, $viteHost, $options)),
            ...$this->runtimeSupportOperations($options),
        ];

        if ($options->traefikHome !== null || $options->servicesHome !== null) {
            $operations[] = WriteFileOperation::createIfMissing(AgentsReadyPath::AGENTS_READY_CONFIG, $this->config($options));
        }

        if (($envTestingExample = $this->envTestingExample($context)) !== null) {
            $operations[] = WriteFileOperation::createIfMissing(AgentsReadyPath::ENV_TESTING_EXAMPLE, $envTestingExample);
        }

        $operations = [
            ...$operations,
            WriteFileOperation::createIfMissing(AgentsReadyPath::AGENTS_READY_WORKTREE, $this->worktreeState($options, $slug, $routeDomain)),
            WriteFileOperation::createIfMissing(AgentsReadyPath::AGENTS_READY_WORKTREES, $this->worktreesIndex($options, $slug, $appHost)),
        ];

        $operations[] = new UpsertMarkedBlockOperation(AgentsReadyPath::AGENTS, AgentsGuidance::START, AgentsGuidance::END, $this->agentsGuidance->section());
        $operations[] = new AppendMissingLinesOperation('.gitignore', $this->gitignoreLines());

        return new InstallationPlan($operations);
    }

    public function existingProjectCandidatesPlan(ProjectContext $context, InstallOptions $options): InstallationPlan
    {
        $this->assertSupportedOptions($options);

        $slug = $this->projectSlug($context, $options);
        $dockerPath = $options->dockerPath;
        $routeDomain = $options->routeDomain ?? $slug.'.localhost';

        return new InstallationPlan([
            WriteFileOperation::conflictIfDifferent(
                AgentsReadyPath::WORKTREE_COMPOSE,
                $this->candidateCompose($slug, $dockerPath, $this->appHost($routeDomain), $this->viteHost($routeDomain), $options),
            ),
        ]);
    }

    private function projectSlug(ProjectContext $context, InstallOptions $options): string
    {
        return $options->projectSlug ?? $context->slug($options->hostRoot);
    }

    private function compose(string $slug, string $dockerPath, string $appHost, string $viteHost, InstallOptions $options): string
    {
        $stub = $options->runtime === 'nginx-php-fpm' ? 'docker-compose.nginx-php-fpm.yml.stub' : 'docker-compose.yml.stub';

        return $this->stubs->render($stub, [
            'slug' => $slug,
            'docker_path' => $dockerPath,
            'app_host' => $appHost,
            'vite_host' => $viteHost,
            'horizon_service' => $options->horizon ? $this->horizonService($dockerPath, $options->runtime) : '',
        ]);
    }

    private function candidateCompose(string $slug, string $dockerPath, string $appHost, string $viteHost, InstallOptions $options): string
    {
        return implode("\n", [
            '# Agents-Ready existing-project candidate companion file.',
            '# Review and manually integrate this file with your project Compose setup.',
            '# It is not wired automatically, and Agents-Ready did not modify user-owned files.',
            '',
        ]).$this->compose($slug, $dockerPath, $appHost, $viteHost, $options);
    }

    private function horizonService(string $dockerPath, string $runtime): string
    {
        $runtimePath = $runtime === 'nginx-php-fpm' ? 'php-fpm' : 'frankenphp';

        return "\n  horizon:\n    build:\n      context: ./{$dockerPath}/{$runtimePath}\n    working_dir: /app\n    command: php artisan horizon\n    profiles:\n      - horizon\n    volumes:\n      - .:/app\n    networks:\n      - default\n      - shared-services\n    environment:\n      HORIZON_PREFIX: \${HORIZON_PREFIX}\n";
    }

    /**
     * @return array<int, WriteFileOperation>
     */
    private function runtimeSupportOperations(InstallOptions $options): array
    {
        if ($options->runtime === 'nginx-php-fpm') {
            return [
                WriteFileOperation::conflictIfDifferent(AgentsReadyPath::phpFpmDockerfile($options->dockerPath), $this->phpFpmDockerfile($options->databaseEngine)),
                WriteFileOperation::conflictIfDifferent(AgentsReadyPath::nginxDefaultConf($options->dockerPath), $this->nginxDefaultConf()),
            ];
        }

        return [
            WriteFileOperation::conflictIfDifferent(AgentsReadyPath::frankenphpDockerfile($options->dockerPath), $this->frankenphpDockerfile($options->databaseEngine)),
            WriteFileOperation::conflictIfDifferent(AgentsReadyPath::frankenphpCaddyfile($options->dockerPath), $this->caddyfile()),
        ];
    }

    private function frankenphpDockerfile(string $databaseEngine): string
    {
        return $this->stubs->render('docker/agents-ready/frankenphp/Dockerfile.stub', $this->databaseDockerfileReplacements($databaseEngine));
    }

    private function phpFpmDockerfile(string $databaseEngine): string
    {
        return $this->stubs->render('docker/agents-ready/php-fpm/Dockerfile.stub', $this->databaseDockerfileReplacements($databaseEngine));
    }

    private function caddyfile(): string
    {
        return $this->stubs->render('docker/agents-ready/frankenphp/Caddyfile.stub');
    }

    private function nginxDefaultConf(): string
    {
        return $this->stubs->render('docker/agents-ready/nginx/default.conf.stub');
    }

    private function worktreeSkill(): string
    {
        return $this->stubs->render('.ai/skills/worktree-lifecycle/SKILL.md.stub');
    }

    private function config(InstallOptions $options): string
    {
        $config = [
            'version' => 1,
            'toolsPath' => $options->toolsPath,
            'dockerPath' => $options->dockerPath,
        ];

        if ($options->traefikHome !== null) {
            $config['traefikHome'] = $options->traefikHome;
        }

        if ($options->servicesHome !== null) {
            $config['servicesHome'] = $options->servicesHome;
        }

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function worktreeState(InstallOptions $options, string $slug, string $routeDomain): string
    {
        return json_encode([
            'version' => 1,
            'root' => $options->hostRoot,
            'mainWorktree' => $options->hostRoot,
            'projectId' => $slug,
            'worktreeId' => $slug,
            'runtime' => $options->runtime,
            'databaseEngine' => $options->databaseEngine,
            'horizon' => $options->horizon,
            'routeDomain' => $routeDomain,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array{database_packages: string, database_extension: string}
     */
    private function databaseDockerfileReplacements(string $databaseEngine): array
    {
        return [
            'database_packages' => $databaseEngine === 'pgsql' ? ' \\'."\n".'        libpq-dev' : '',
            'database_extension' => $databaseEngine === 'mysql' ? 'pdo_mysql' : 'pdo_pgsql',
        ];
    }

    private function worktreesIndex(InstallOptions $options, string $slug, string $appHost): string
    {
        return json_encode([
            'version' => 1,
            'worktrees' => [
                [
                    'id' => $slug,
                    'path' => $options->hostRoot,
                    'branch' => '',
                    'appHost' => $appHost,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function appHost(string $routeDomain): string
    {
        return $routeDomain;
    }

    private function viteHost(string $routeDomain): string
    {
        return 'vite.'.$routeDomain;
    }

    private function envTestingExample(ProjectContext $context): ?string
    {
        foreach ([AgentsReadyPath::ENV_TESTING, AgentsReadyPath::ENV, AgentsReadyPath::ENV_EXAMPLE] as $path) {
            $content = $this->fileContent($context->root, $path);

            if ($content !== null) {
                return $content;
            }
        }

        return null;
    }

    private function fileContent(string $root, string $path): ?string
    {
        $target = $root.'/'.$path;

        if (! is_file($target)) {
            return null;
        }

        $content = file_get_contents($target);

        return is_string($content) ? $content : null;
    }

    /**
     * @return array<int, string>
     */
    private function gitignoreLines(): array
    {
        return [
            '# Agents-Ready Laravel local state',
            $this->ignoreDirectory(AgentsReadyPath::AGENTS_READY),
            $this->ignorePath(AgentsReadyPath::ENV_TESTING),
            $this->ignorePath(AgentsReadyPath::OPENCODE),
            $this->ignorePath(AgentsReadyPath::MCP),
            $this->ignorePath(AgentsReadyPath::AUTH),
        ];
    }

    private function assertSupportedOptions(InstallOptions $options): void
    {
        if (! InstallOptions::supportsRuntime($options->runtime)) {
            throw new InvalidArgumentException('Option --runtime must be one of: '.implode(', ', InstallOptions::RUNTIMES).'.');
        }

        if (! InstallOptions::supportsDatabaseEngine($options->databaseEngine)) {
            throw new InvalidArgumentException('Option --database must be one of: '.implode(', ', InstallOptions::DATABASE_ENGINES).'.');
        }
    }

    private function ignorePath(string $path): string
    {
        return '/'.$path;
    }

    private function ignoreDirectory(string $path): string
    {
        return '/'.rtrim($path, '/').'/';
    }
}
