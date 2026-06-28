<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Install;

final readonly class InstallOptions
{
    public const DEFAULT_RUNTIME = 'frankenphp';

    /** @var array<int, string> */
    public const RUNTIMES = ['frankenphp', 'nginx-php-fpm'];

    public const DEFAULT_DATABASE_ENGINE = 'pgsql';

    /** @var array<int, string> */
    public const DATABASE_ENGINES = ['pgsql', 'mysql'];

    public function __construct(
        public string $hostRoot,
        public bool $decisionNotes = false,
        public bool $localAgentNotes = false,
        public bool $agentSkills = false,
        public string $toolsPath = 'tools/agent-ready',
        public string $dockerPath = 'docker/agent-ready',
        public ?string $traefikHome = null,
        public ?string $servicesHome = null,
        public ?string $routeDomain = null,
        public string $runtime = self::DEFAULT_RUNTIME,
        public string $databaseEngine = self::DEFAULT_DATABASE_ENGINE,
        public bool $horizon = false,
    ) {}

    public static function supportsRuntime(string $runtime): bool
    {
        return in_array($runtime, self::RUNTIMES, true);
    }

    public static function supportsDatabaseEngine(string $databaseEngine): bool
    {
        return in_array($databaseEngine, self::DATABASE_ENGINES, true);
    }
}
