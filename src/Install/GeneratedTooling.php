<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install;

use AgentsReady\LaravelInstaller\Install\Support\StubRenderer;

final class GeneratedTooling
{
    public function __construct(
        private readonly StubRenderer $stubs = new StubRenderer,
    ) {}

    public function localWrapper(string $toolsPath): string
    {
        return $this->stubs->render('local.stub', ['tools_path' => $toolsPath]);
    }

    public function localDispatcher(): string
    {
        return $this->stubs->render('tools/agents-ready/local.sh.stub');
    }

    public function commonShellLibrary(): string
    {
        return $this->stubs->render('tools/agents-ready/lib/common.sh.stub');
    }

    public function worktreesShellLibrary(): string
    {
        return $this->stubs->render('tools/agents-ready/lib/worktrees.sh.stub');
    }

    public function agentsShellLibrary(): string
    {
        return $this->stubs->render('tools/agents-ready/lib/agents.sh.stub');
    }
}
