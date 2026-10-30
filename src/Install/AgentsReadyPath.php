<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Install;

final class AgentsReadyPath
{
    public const LOCAL = 'local';

    public const COMPOSE = 'docker-compose.yml';

    public const WORKTREE_COMPOSE = 'worktree.docker-compose.yml';

    public const AGENTS = 'AGENTS.md';

    public const AGENTS_LOCAL = 'AGENTS.local.md';

    public const AGENTS_LOCAL_EXAMPLE = 'AGENTS.local.example.md';

    public const OPENCODE_EXAMPLE = 'opencode.jsonc.example';

    public const OPENCODE = 'opencode.jsonc';

    public const MCP = '.mcp.json';

    public const AUTH = 'auth.json';

    public const ENV = '.env';

    public const ENV_EXAMPLE = '.env.example';

    public const ENV_TESTING = '.env.testing';

    public const ENV_TESTING_EXAMPLE = '.env.testing.example';

    public const AGENTS_READY = '.agents-ready';

    public const AGENTS_READY_CONFIG = '.agents-ready/config.json';

    public const AGENTS_READY_WORKTREE = '.agents-ready/worktree.json';

    public const AGENTS_READY_WORKTREES = '.agents-ready/worktrees.json';

    public const AI_SKILLS = '.ai/skills';

    public const AGENTS_SKILLS = '.agents/skills';

    public const CLAUDE_SKILLS = '.claude/skills';

    public const CURSOR_SKILLS = '.cursor/skills';

    public const AI_BOOTSTRAP_SKILL = '.ai/skills/bootstrap-current-checkout/SKILL.md';

    public const AI_WORKTREE_SKILL = '.ai/skills/worktree-lifecycle/SKILL.md';

    public const CORE_WORKTREE_SKILL_NAME = 'worktree-lifecycle/SKILL.md';

    public const DECISION_NOTES_README = 'docs/decision-notes/README.md';

    private function __construct() {}

    public static function localDispatcher(string $toolsPath): string
    {
        return self::join($toolsPath, 'local.sh');
    }

    public static function commonLibrary(string $toolsPath): string
    {
        return self::join($toolsPath, 'lib/common.sh');
    }

    public static function agentsLibrary(string $toolsPath): string
    {
        return self::join($toolsPath, 'lib/agents.sh');
    }

    public static function worktreesLibrary(string $toolsPath): string
    {
        return self::join($toolsPath, 'lib/worktrees.sh');
    }

    public static function coreWorktreeSkill(string $toolsPath): string
    {
        return self::join($toolsPath, 'skills/'.self::CORE_WORKTREE_SKILL_NAME);
    }

    public static function frankenphpDockerfile(string $dockerPath): string
    {
        return self::join($dockerPath, 'frankenphp/Dockerfile');
    }

    public static function frankenphpCaddyfile(string $dockerPath): string
    {
        return self::join($dockerPath, 'frankenphp/Caddyfile');
    }

    public static function phpFpmDockerfile(string $dockerPath): string
    {
        return self::join($dockerPath, 'php-fpm/Dockerfile');
    }

    public static function nginxDefaultConf(string $dockerPath): string
    {
        return self::join($dockerPath, 'nginx/default.conf');
    }

    private static function join(string $prefix, string $suffix): string
    {
        return rtrim($prefix, '/').'/'.ltrim($suffix, '/');
    }
}
