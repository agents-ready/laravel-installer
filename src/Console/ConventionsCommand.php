<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Console;

use AgentsReady\LaravelInstaller\Install\AgentsReadyPath;
use AgentsReady\LaravelInstaller\Install\ConventionOptions;
use AgentsReady\LaravelInstaller\Install\ConventionPlanner;
use AgentsReady\LaravelInstaller\Install\Plan\InstallPlanApplier;
use AgentsReady\LaravelInstaller\Install\ProjectContext;
use AgentsReady\LaravelInstaller\Install\Support\PathInput;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

final class ConventionsCommand extends Command
{
    protected $signature = 'agents-ready:conventions
        {--decision-notes : Include the optional docs/decision-notes project convention}
        {--local-agent-notes : Include AGENTS.local.example.md and initialize ignored AGENTS.local.md}
        {--agent-skills : Include tracked .ai/skills and ignored activation-folder convention}
        {--tools-path= : Relative path for generated Agents-Ready host scripts; defaults from .agents-ready/config.json}';

    protected $description = 'Install optional Agents-Ready project/team conventions';

    public function __construct(
        private readonly ConventionPlanner $planner,
        private readonly InstallPlanApplier $installer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $context = ProjectContext::fromPath(base_path());

        try {
            $toolsPath = $this->toolsPathOption($context->root);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $decisionNotes = (bool) $this->option('decision-notes');
        $localAgentNotes = (bool) $this->option('local-agent-notes');
        $agentSkills = (bool) $this->option('agent-skills');
        $hasAnyExplicitConvention = $decisionNotes || $localAgentNotes || $agentSkills;

        if ($this->input->isInteractive() && ! $hasAnyExplicitConvention) {
            $decisionNotes = $this->promptForDecisionNotes($context->root);
            $localAgentNotes = $this->promptForLocalAgentNotes($context->root);
            $agentSkills = $this->promptForAgentSkills($context->root);
        }

        $options = new ConventionOptions(
            decisionNotes: $decisionNotes,
            localAgentNotes: $localAgentNotes,
            agentSkills: $agentSkills,
            toolsPath: $toolsPath,
        );

        if (! $options->any()) {
            $this->components->info('No conventions selected. Core Agents-Ready tooling was left unchanged.');

            return self::SUCCESS;
        }

        if ($options->agentSkills && ! $this->hasCoreGeneratedTooling($context->root, $toolsPath)) {
            $this->components->error('Agent skills convention requires generated wrapper hooks. Run agents-ready:install first, or pass --tools-path for the existing generated tools path.');

            return self::FAILURE;
        }

        try {
            $plan = $this->planner->plan($context, $options);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $result = $this->installer->apply($context->root, $plan);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result->conflictPaths as $path) {
            $this->components->warn("Conflict: {$path}");
        }

        if (! $result->successful()) {
            $this->components->error('Convention setup stopped because existing files would be overwritten.');
            $this->components->info('Agents-Ready did not modify any files. Review the conflicting files, then rerun with the desired convention options.');

            return self::FAILURE;
        }

        foreach ($result->writtenPaths as $path) {
            $this->line("Wrote {$path}");
        }

        foreach ($result->initializedPaths as $path) {
            $this->line("Initialized {$path}");
        }

        foreach ($result->updatedPaths as $path) {
            $this->line("Updated {$path}");
        }

        if ($result->writtenPaths === [] && $result->initializedPaths === [] && $result->updatedPaths === []) {
            $this->components->info('Selected convention files were already up to date.');
        }

        if ($options->agentSkills) {
            $this->components->info('Run ./local agents link to populate ignored skill activation links.');
        }

        return self::SUCCESS;
    }

    private function promptForDecisionNotes(string $root): bool
    {
        if ($this->pathExists($root, AgentsReadyPath::DECISION_NOTES_README)) {
            return false;
        }

        $this->line('Decision notes add an optional docs/decision-notes convention for recording project decisions.');

        return $this->confirm('Add decision-notes guidance?');
    }

    private function promptForLocalAgentNotes(string $root): bool
    {
        if ($this->pathExists($root, AgentsReadyPath::AGENTS_LOCAL) || $this->pathExists($root, AgentsReadyPath::AGENTS_LOCAL_EXAMPLE)) {
            return false;
        }

        $this->line('Local agent notes add a tracked AGENTS.local.example.md and ignored AGENTS.local.md for machine-specific notes, secrets references, and personal agent preferences.');

        return $this->confirm('Add local agent notes convention?');
    }

    private function promptForAgentSkills(string $root): bool
    {
        if (is_dir($root.'/'.AgentsReadyPath::AI_SKILLS) || $this->pathExists($root, AgentsReadyPath::AGENTS_SKILLS)) {
            return false;
        }

        $this->line('Agent skills mirror the core worktree skill into tracked .ai/skills and add ignored activation folders for tools that support skills.');

        return $this->confirm('Add agent skills convention?');
    }

    private function toolsPathOption(string $root): string
    {
        $default = $this->configString($root, 'toolsPath') ?? 'tools/agents-ready';

        if (! $this->optionWasPassed('tools-path')) {
            return PathInput::relativeOption('tools-path', $default);
        }

        $value = $this->option('tools-path');

        return PathInput::relativeOption('tools-path', is_string($value) ? $value : '');
    }

    private function hasCoreGeneratedTooling(string $root, string $toolsPath): bool
    {
        return $this->pathExists($root, AgentsReadyPath::localDispatcher($toolsPath))
            && $this->pathExists($root, AgentsReadyPath::worktreesLibrary($toolsPath));
    }

    private function pathExists(string $root, string $path): bool
    {
        $target = $root.'/'.$path;

        return file_exists($target) || is_link($target);
    }

    private function optionWasPassed(string $name): bool
    {
        return $this->input->hasParameterOption('--'.$name);
    }

    private function configString(string $root, string $key): ?string
    {
        $config = $this->agentsReadyConfig($root);
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function agentsReadyConfig(string $root): array
    {
        $path = $root.'/'.AgentsReadyPath::AGENTS_READY_CONFIG;

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
