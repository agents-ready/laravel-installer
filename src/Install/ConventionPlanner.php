<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install;

use AgentsReady\LaravelInstaller\Install\Operations\AppendMissingLinesOperation;
use AgentsReady\LaravelInstaller\Install\Operations\UpsertMarkedBlockOperation;
use AgentsReady\LaravelInstaller\Install\Operations\WriteFileOperation;
use AgentsReady\LaravelInstaller\Install\Plan\InstallationPlan;
use AgentsReady\LaravelInstaller\Install\Support\StubRenderer;

final class ConventionPlanner
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

    public function plan(ProjectContext $context, ConventionOptions $options): InstallationPlan
    {
        $operations = [];

        if ($options->localAgentNotes) {
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentsReadyPath::AGENTS_LOCAL_EXAMPLE, $this->agentsLocalExample());
            $operations[] = WriteFileOperation::createIfMissing(AgentsReadyPath::AGENTS_LOCAL, $this->agentsLocal());
        }

        if ($options->agentSkills) {
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentsReadyPath::agentsLibrary($options->toolsPath), $this->tooling->agentsShellLibrary());
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentsReadyPath::AI_BOOTSTRAP_SKILL, $this->bootstrapSkill());
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentsReadyPath::AI_WORKTREE_SKILL, $this->worktreeSkill());
        }

        if ($options->decisionNotes) {
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentsReadyPath::DECISION_NOTES_README, $this->decisionNotesReadme());
        }

        if ($options->localAgentNotes || $options->agentSkills) {
            $operations[] = WriteFileOperation::conflictIfDifferent(AgentsReadyPath::OPENCODE_EXAMPLE, $this->opencodeExample($options));
            $operations[] = new UpsertMarkedBlockOperation(
                AgentsReadyPath::AGENTS,
                AgentsGuidance::START,
                AgentsGuidance::END,
                $this->agentsGuidance->section($options),
            );
            $operations[] = new AppendMissingLinesOperation('.gitignore', $this->gitignoreLines($context, $options));
        }

        return new InstallationPlan($operations);
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

    private function decisionNotesReadme(): string
    {
        return $this->stubs->render('docs/decision-notes/README.md.stub');
    }

    private function opencodeExample(ConventionOptions $options): string
    {
        $instructions = ['"'.AgentsReadyPath::AGENTS.'"'];

        if ($options->localAgentNotes) {
            $instructions[] = '"'.AgentsReadyPath::AGENTS_LOCAL.'"';
        }

        return $this->stubs->render('opencode.jsonc.example.stub', [
            'instructions' => implode(', ', $instructions),
            'skill_config' => $options->agentSkills ? $this->opencodeSkillConfig() : '',
        ]);
    }

    private function opencodeSkillConfig(): string
    {
        return $this->stubs->render('opencode.skill-config.json.stub', [
            'agents_skills' => AgentsReadyPath::AGENTS_SKILLS,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function gitignoreLines(ProjectContext $context, ConventionOptions $options): array
    {
        $lines = [];

        if ($options->agentSkills || is_dir($context->root.'/'.AgentsReadyPath::AI_SKILLS)) {
            $lines[] = $this->ignoreDirectory(AgentsReadyPath::AGENTS_SKILLS);
            $lines[] = $this->ignorePath(AgentsReadyPath::CLAUDE_SKILLS);
            $lines[] = $this->ignorePath(AgentsReadyPath::CURSOR_SKILLS);
        }

        if ($options->localAgentNotes || $this->pathExists($context->root, AgentsReadyPath::AGENTS_LOCAL)) {
            $lines[] = $this->ignorePath(AgentsReadyPath::AGENTS_LOCAL);
        }

        return $lines;
    }

    private function pathExists(string $root, string $path): bool
    {
        $target = $root.'/'.$path;

        return file_exists($target) || is_link($target);
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
