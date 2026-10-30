<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install;

final readonly class ConventionOptions
{
    public function __construct(
        public bool $decisionNotes = false,
        public bool $localAgentNotes = false,
        public bool $agentSkills = false,
        public string $toolsPath = 'tools/agents-ready',
    ) {}

    public function any(): bool
    {
        return $this->decisionNotes || $this->localAgentNotes || $this->agentSkills;
    }
}
