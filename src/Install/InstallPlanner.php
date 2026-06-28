<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install;

use EdgarSedov\AgentReadyInstaller\Install\Operations\AppendMissingLinesOperation;
use EdgarSedov\AgentReadyInstaller\Install\Operations\UpsertMarkedBlockOperation;
use EdgarSedov\AgentReadyInstaller\Install\Operations\WriteFileOperation;
use EdgarSedov\AgentReadyInstaller\Install\Plan\InstallationPlan;
use EdgarSedov\AgentReadyInstaller\Install\Support\StubRenderer;
use InvalidArgumentException;

final class InstallPlanner
{
    private const AGENTS_START = '<!-- agent-ready:start -->';

    private const AGENTS_END = '<!-- agent-ready:end -->';

    private readonly StubRenderer $stubs;

    public function __construct(?StubRenderer $stubs = null)
    {
        $this->stubs = $stubs ?? new StubRenderer;
    }

    public function plan(ProjectContext $context, InstallOptions $options): InstallationPlan
    {
        $this->assertSupportedOptions($options);

        $slug = $context->slug();
        $toolsPath = $options->toolsPath;
        $dockerPath = $options->dockerPath;
        $routeDomain = $options->routeDomain ?? $context->defaultRouteDomain();
        $appHost = $this->appHost($routeDomain);
        $viteHost = $this->viteHost($routeDomain);

        $operations = [
            WriteFileOperation::conflictIfDifferent(AgentReadyPath::LOCAL, $this->localWrapper($toolsPath), 0755),
            WriteFileOperation::conflictIfDifferent(AgentReadyPath::localDispatcher($toolsPath), $this->localDispatcher($options), 0755),
            WriteFileOperation::conflictIfDifferent(AgentReadyPath::commonLibrary($toolsPath), $this->commonShellLibrary()),
            WriteFileOperation::conflictIfDifferent(AgentReadyPath::worktreesLibrary($toolsPath), $this->worktreesShellLibrary($options)),
            WriteFileOperation::conflictIfDifferent(AgentReadyPath::COMPOSE, $this->compose($slug, $dockerPath, $appHost, $viteHost, $options)),
            ...$this->runtimeSupportOperations($options),
            WriteFileOperation::conflictIfDifferent(AgentReadyPath::OPENCODE_EXAMPLE, $this->opencodeExample($options)),
        ];

        if ($options->localAgentNotes) {
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentReadyPath::AGENTS_LOCAL_EXAMPLE, $this->agentsLocalExample());
        }

        if ($options->agentSkills) {
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentReadyPath::agentsLibrary($toolsPath), $this->agentsShellLibrary());
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentReadyPath::BOOTSTRAP_SKILL, $this->bootstrapSkill());
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentReadyPath::WORKTREE_SKILL, $this->worktreeSkill());
        }

        if ($options->decisionNotes) {
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentReadyPath::DECISION_NOTES_README, $this->decisionNotesReadme());
        }

        if ($options->traefikHome !== null || $options->servicesHome !== null) {
            $operations[] = WriteFileOperation::createIfMissing(AgentReadyPath::AGENT_READY_CONFIG, $this->config($options->traefikHome, $options->servicesHome));
        }

        if (($envTestingExample = $this->envTestingExample($context)) !== null) {
            $operations[] = WriteFileOperation::createIfMissing(AgentReadyPath::ENV_TESTING_EXAMPLE, $envTestingExample);
        }

        $operations = [
            ...$operations,
            WriteFileOperation::createIfMissing(AgentReadyPath::AGENT_READY_WORKTREE, $this->worktreeState($options, $slug, $routeDomain)),
            WriteFileOperation::createIfMissing(AgentReadyPath::AGENT_READY_WORKTREES, $this->worktreesIndex($options, $slug, $appHost)),
        ];

        if ($options->localAgentNotes) {
            $operations[] = WriteFileOperation::createIfMissing(AgentReadyPath::AGENTS_LOCAL, $this->agentsLocal());
        }

        $operations[] = new UpsertMarkedBlockOperation(AgentReadyPath::AGENTS, self::AGENTS_START, self::AGENTS_END, $this->agentsSection($options));
        $operations[] = new AppendMissingLinesOperation('.gitignore', $this->gitignoreLines($context, $options));

        return new InstallationPlan($operations);
    }

    public function existingProjectCandidatesPlan(ProjectContext $context, InstallOptions $options): InstallationPlan
    {
        $this->assertSupportedOptions($options);

        $slug = $context->slug();
        $dockerPath = $options->dockerPath;
        $routeDomain = $options->routeDomain ?? $context->defaultRouteDomain();

        return new InstallationPlan([
            WriteFileOperation::conflictIfDifferent(
                AgentReadyPath::WORKTREE_COMPOSE,
                $this->candidateCompose($slug, $dockerPath, $this->appHost($routeDomain), $this->viteHost($routeDomain), $options),
            ),
        ]);
    }

    private function localWrapper(string $toolsPath): string
    {
        return $this->stubs->render('local.stub', ['tools_path' => $toolsPath]);
    }

    private function localDispatcher(InstallOptions $options): string
    {
        return $this->stubs->render('tools/agent-ready/local.sh.stub', [
            'agent_source_line' => $options->agentSkills ? $this->stubs->render('tools/agent-ready/fragments/agents-source-line.sh.stub') : '',
            'agent_usage_line' => $options->agentSkills ? $this->stubs->render('tools/agent-ready/fragments/agents-usage-line.txt.stub') : '',
            'agent_command_case' => $options->agentSkills ? $this->stubs->render('tools/agent-ready/fragments/agents-command-case.sh.stub') : '',
        ]);
    }

    private function commonShellLibrary(): string
    {
        return $this->stubs->render('tools/agent-ready/lib/common.sh.stub');
    }

    private function agentsShellLibrary(): string
    {
        return $this->stubs->render('tools/agent-ready/lib/agents.sh.stub');
    }

    private function worktreesShellLibrary(InstallOptions $options): string
    {
        return $this->stubs->render('tools/agent-ready/lib/worktrees.sh.stub', [
            'agent_link_after_create' => $options->agentSkills ? '  (cd "$target" && ./local agents link)' : '',
        ]);
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
            '# Agent-Ready existing-project candidate companion file.',
            '# Review and manually integrate this file with your project Compose setup.',
            '# It is not wired automatically, and Agent-Ready did not modify user-owned files.',
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
                WriteFileOperation::conflictIfDifferent(AgentReadyPath::phpFpmDockerfile($options->dockerPath), $this->phpFpmDockerfile($options->databaseEngine)),
                WriteFileOperation::conflictIfDifferent(AgentReadyPath::nginxDefaultConf($options->dockerPath), $this->nginxDefaultConf()),
            ];
        }

        return [
            WriteFileOperation::conflictIfDifferent(AgentReadyPath::frankenphpDockerfile($options->dockerPath), $this->frankenphpDockerfile($options->databaseEngine)),
            WriteFileOperation::conflictIfDifferent(AgentReadyPath::frankenphpCaddyfile($options->dockerPath), $this->caddyfile()),
        ];
    }

    private function frankenphpDockerfile(string $databaseEngine): string
    {
        return $this->stubs->render('docker/agent-ready/frankenphp/Dockerfile.stub', $this->databaseDockerfileReplacements($databaseEngine));
    }

    private function phpFpmDockerfile(string $databaseEngine): string
    {
        return $this->stubs->render('docker/agent-ready/php-fpm/Dockerfile.stub', $this->databaseDockerfileReplacements($databaseEngine));
    }

    private function caddyfile(): string
    {
        return $this->stubs->render('docker/agent-ready/frankenphp/Caddyfile.stub');
    }

    private function nginxDefaultConf(): string
    {
        return $this->stubs->render('docker/agent-ready/nginx/default.conf.stub');
    }

    public function agentsSection(InstallOptions $options): string
    {
        return $this->stubs->render('AGENTS.agent-ready-section.md.stub', [
            'local_agent_notes_guidance' => $options->localAgentNotes ? $this->localAgentNotesGuidance() : '',
            'agent_skills_guidance' => $options->agentSkills ? $this->agentSkillsGuidance() : '',
        ]);
    }

    private function localAgentNotesGuidance(): string
    {
        return $this->stubs->render('AGENTS.local-guidance.md.stub', [
            'agents_local' => AgentReadyPath::AGENTS_LOCAL,
        ]);
    }

    private function agentSkillsGuidance(): string
    {
        return $this->stubs->render('AGENTS.skills-guidance.md.stub', [
            'ai_skills' => AgentReadyPath::AI_SKILLS,
            'agents_skills' => AgentReadyPath::AGENTS_SKILLS,
            'claude_skills' => AgentReadyPath::CLAUDE_SKILLS,
            'cursor_skills' => AgentReadyPath::CURSOR_SKILLS,
        ]);
    }

    private function agentsLocalExample(): string
    {
        return $this->stubs->render('AGENTS.local.example.md.stub');
    }

    private function agentsLocal(): string
    {
        return $this->stubs->render('AGENTS.local.example.md.stub');
    }

    private function bootstrapSkill(): string
    {
        return $this->stubs->render('.ai/skills/bootstrap-current-checkout/SKILL.md.stub');
    }

    private function worktreeSkill(): string
    {
        return $this->stubs->render('.ai/skills/worktree-lifecycle/SKILL.md.stub');
    }

    private function opencodeExample(InstallOptions $options): string
    {
        $instructions = ['"'.AgentReadyPath::AGENTS.'"'];

        if ($options->localAgentNotes) {
            $instructions[] = '"'.AgentReadyPath::AGENTS_LOCAL.'"';
        }

        return $this->stubs->render('opencode.jsonc.example.stub', [
            'instructions' => implode(', ', $instructions),
            'skill_config' => $options->agentSkills ? $this->opencodeSkillConfig() : '',
        ]);
    }

    private function opencodeSkillConfig(): string
    {
        return $this->stubs->render('opencode.skill-config.json.stub', [
            'agents_skills' => AgentReadyPath::AGENTS_SKILLS,
        ]);
    }

    private function config(?string $traefikHome, ?string $servicesHome): string
    {
        $config = [
            'version' => 1,
        ];

        if ($traefikHome !== null) {
            $config['traefikHome'] = $traefikHome;
        }

        if ($servicesHome !== null) {
            $config['servicesHome'] = $servicesHome;
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

    private function decisionNotesReadme(): string
    {
        return $this->stubs->render('docs/decision-notes/README.md.stub');
    }

    private function envTestingExample(ProjectContext $context): ?string
    {
        foreach ([AgentReadyPath::ENV_TESTING, AgentReadyPath::ENV, AgentReadyPath::ENV_EXAMPLE] as $path) {
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
    private function gitignoreLines(ProjectContext $context, InstallOptions $options): array
    {
        $lines = [
            '# Agent-Ready Laravel local state',
            $this->ignoreDirectory(AgentReadyPath::AGENT_READY),
            $this->ignorePath(AgentReadyPath::ENV_TESTING),
            $this->ignorePath(AgentReadyPath::OPENCODE),
            $this->ignorePath(AgentReadyPath::MCP),
            $this->ignorePath(AgentReadyPath::AUTH),
        ];

        if ($options->agentSkills || is_dir($context->root.'/'.AgentReadyPath::AI_SKILLS)) {
            $lines[] = $this->ignoreDirectory(AgentReadyPath::AGENTS_SKILLS);
            $lines[] = $this->ignorePath(AgentReadyPath::CLAUDE_SKILLS);
            $lines[] = $this->ignorePath(AgentReadyPath::CURSOR_SKILLS);
        }

        if ($options->localAgentNotes || $this->pathExists($context->root, AgentReadyPath::AGENTS_LOCAL)) {
            $lines[] = $this->ignorePath(AgentReadyPath::AGENTS_LOCAL);
        }

        return $lines;
    }

    private function pathExists(string $root, string $path): bool
    {
        $target = $root.'/'.$path;

        return file_exists($target) || is_link($target);
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
