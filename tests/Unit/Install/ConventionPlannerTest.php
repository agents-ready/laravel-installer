<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests\Unit\Install;

use AgentsReady\LaravelInstaller\Install\AgentsGuidance;
use AgentsReady\LaravelInstaller\Install\ConventionOptions;
use AgentsReady\LaravelInstaller\Install\ConventionPlanner;
use AgentsReady\LaravelInstaller\Install\Operations\AppendMissingLinesOperation;
use AgentsReady\LaravelInstaller\Install\Operations\UpsertMarkedBlockOperation;
use AgentsReady\LaravelInstaller\Install\Operations\WriteFileOperation;
use AgentsReady\LaravelInstaller\Install\Plan\InstallationPlan;
use AgentsReady\LaravelInstaller\Install\ProjectContext;
use AgentsReady\LaravelInstaller\Tests\UnitTestCase;

final class ConventionPlannerTest extends UnitTestCase
{
    public function test_decision_notes_are_a_separate_convention(): void
    {
        $plan = (new ConventionPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            new ConventionOptions(decisionNotes: true),
        );

        $this->assertSame(['docs/decision-notes/README.md'], $plan->paths());
        $this->assertStringContainsString('Decision Notes', $this->contentFor($plan, 'docs/decision-notes/README.md'));
    }

    public function test_local_agent_notes_are_a_separate_convention(): void
    {
        $plan = (new ConventionPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            new ConventionOptions(localAgentNotes: true),
        );
        $paths = $plan->paths();

        $this->assertContains('AGENTS.local.example.md', $paths);
        $this->assertContains('AGENTS.local.md', $paths);
        $this->assertContains('AGENTS.md', $paths);
        $this->assertContains('.gitignore', $paths);
        $this->assertContains('opencode.jsonc.example', $paths);
        $this->assertTrue($this->writeOperationFor($plan, 'AGENTS.local.md')->createsIfMissing());
        $this->assertStringContainsString('Local Agent Notes', $this->contentFor($plan, 'AGENTS.local.md'));
        $this->assertStringContainsString('"instructions": ["AGENTS.md", "AGENTS.local.md"]', $this->contentFor($plan, 'opencode.jsonc.example'));

        $agentsOperation = $plan->operationFor('AGENTS.md');
        $gitignoreOperation = $plan->operationFor('.gitignore');

        $this->assertInstanceOf(UpsertMarkedBlockOperation::class, $agentsOperation);
        $this->assertStringContainsString('Also read `AGENTS.local.md` when it exists', $agentsOperation->body);
        $this->assertInstanceOf(AppendMissingLinesOperation::class, $gitignoreOperation);
        $this->assertContains('/AGENTS.local.md', $gitignoreOperation->lines);
    }

    public function test_agent_skills_convention_adds_opinionated_skill_paths_and_linking(): void
    {
        $plan = (new ConventionPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            new ConventionOptions(agentSkills: true),
        );
        $paths = $plan->paths();

        $this->assertContains('tools/agents-ready/lib/agents.sh', $paths);
        $this->assertContains('.ai/skills/bootstrap-current-checkout/SKILL.md', $paths);
        $this->assertContains('.ai/skills/worktree-lifecycle/SKILL.md', $paths);
        $this->assertContains('opencode.jsonc.example', $paths);
        $this->assertStringContainsString('agents_ready_handle_convention_command', $this->contentFor($plan, 'tools/agents-ready/lib/agents.sh'));
        $this->assertStringContainsString('./local agents link', $this->contentFor($plan, 'tools/agents-ready/lib/agents.sh'));
        $this->assertStringContainsString('"skill": [".agents/skills"]', $this->contentFor($plan, 'opencode.jsonc.example'));

        $agentsSection = (new AgentsGuidance)->section(new ConventionOptions(agentSkills: true));
        $gitignoreOperation = $plan->operationFor('.gitignore');

        $this->assertStringContainsString('tracked skill sources live in `.ai/skills`', $agentsSection);
        $this->assertStringContainsString('Run `./local agents link`', $agentsSection);
        $this->assertStringContainsString('not generated activation links', $agentsSection);
        $this->assertInstanceOf(AppendMissingLinesOperation::class, $gitignoreOperation);
        $this->assertContains('/.agents/skills/', $gitignoreOperation->lines);
        $this->assertContains('/.claude/skills', $gitignoreOperation->lines);
        $this->assertContains('/.cursor/skills', $gitignoreOperation->lines);
    }

    public function test_agent_skills_convention_uses_custom_tools_path(): void
    {
        $plan = (new ConventionPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            new ConventionOptions(agentSkills: true, toolsPath: 'scripts/dev'),
        );

        $this->assertContains('scripts/dev/lib/agents.sh', $plan->paths());
    }

    public function test_empty_convention_plan_has_no_operations(): void
    {
        $plan = (new ConventionPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            new ConventionOptions,
        );

        $this->assertSame([], $plan->paths());
    }

    public function test_rendered_convention_operation_contents_have_no_unresolved_tokens(): void
    {
        $plan = (new ConventionPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            new ConventionOptions(decisionNotes: true, localAgentNotes: true, agentSkills: true),
        );

        foreach ($plan->operations() as $operation) {
            if ($operation instanceof WriteFileOperation) {
                $this->assertDoesNotMatchRegularExpression('/{{\s*[A-Za-z0-9_]+\s*}}/', $operation->content, $operation->path);
            }

            if ($operation instanceof UpsertMarkedBlockOperation) {
                $this->assertDoesNotMatchRegularExpression('/{{\s*[A-Za-z0-9_]+\s*}}/', $operation->body, $operation->path);
            }
        }
    }

    public function test_generated_convention_shell_files_are_valid_bash(): void
    {
        $plan = (new ConventionPlanner)->plan(
            ProjectContext::fromPath($this->makeProjectRoot()),
            new ConventionOptions(agentSkills: true),
        );

        foreach (['tools/agents-ready/lib/agents.sh'] as $generatedPath) {
            $path = $this->temporaryPath('agents-ready-convention-shell-');
            file_put_contents($path, $this->contentFor($plan, $generatedPath));

            $output = [];
            $exitCode = 0;
            exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exitCode);

            $this->assertSame(0, $exitCode, $generatedPath."\n".implode("\n", $output));
        }
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

    private function makeProjectRoot(): string
    {
        $root = $this->temporaryDirectory('agents-ready-convention-planner-test-');

        $this->writeLaravelComposerJson($root);

        return $root;
    }
}
