<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install;

use AgentsReady\LaravelInstaller\Install\Support\StubRenderer;

final class AgentsGuidance
{
    public const START = '<!-- agents-ready:start -->';

    public const END = '<!-- agents-ready:end -->';

    public function __construct(
        private readonly StubRenderer $stubs = new StubRenderer,
    ) {}

    public function section(?ConventionOptions $conventions = null): string
    {
        $conventions ??= new ConventionOptions;

        return $this->stubs->render('AGENTS.agents-ready-section.md.stub', [
            'local_agent_notes_guidance' => $conventions->localAgentNotes ? $this->localAgentNotesGuidance() : '',
            'agent_skills_guidance' => $conventions->agentSkills ? $this->agentSkillsGuidance() : '',
        ]);
    }

    private function localAgentNotesGuidance(): string
    {
        return $this->stubs->render('AGENTS.local-guidance.md.stub', [
            'agents_local' => AgentsReadyPath::AGENTS_LOCAL,
        ]);
    }

    private function agentSkillsGuidance(): string
    {
        return $this->stubs->render('AGENTS.skills-guidance.md.stub', [
            'ai_skills' => AgentsReadyPath::AI_SKILLS,
            'agents_skills' => AgentsReadyPath::AGENTS_SKILLS,
            'claude_skills' => AgentsReadyPath::CLAUDE_SKILLS,
            'cursor_skills' => AgentsReadyPath::CURSOR_SKILLS,
        ]);
    }
}
