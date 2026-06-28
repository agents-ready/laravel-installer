<?php

declare(strict_types=1);

namespace EdgarSedov\AgentReadyInstaller\Console;

use EdgarSedov\AgentReadyInstaller\Install\AgentReadyPath;
use EdgarSedov\AgentReadyInstaller\Install\Detection\ExistingProjectDetector;
use EdgarSedov\AgentReadyInstaller\Install\Detection\ExistingProjectFacts;
use EdgarSedov\AgentReadyInstaller\Install\InstallOptions;
use EdgarSedov\AgentReadyInstaller\Install\InstallPlanner;
use EdgarSedov\AgentReadyInstaller\Install\Operations\WriteFileOperation;
use EdgarSedov\AgentReadyInstaller\Install\Plan\InstallationPlan;
use EdgarSedov\AgentReadyInstaller\Install\Plan\InstallPlanApplier;
use EdgarSedov\AgentReadyInstaller\Install\ProjectContext;
use EdgarSedov\AgentReadyInstaller\Install\Support\PathInput;
use FilesystemIterator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class InstallCommand extends Command
{
    protected $signature = 'agent-ready:install
        {--host-root= : Absolute host path to persist for managed worktree state when running inside Docker}
        {--runtime=frankenphp : Runtime to generate: frankenphp or nginx-php-fpm}
        {--database=pgsql : Database engine to use: pgsql or mysql}
        {--horizon : Include an optional Horizon worker service}
        {--decision-notes : Include the optional docs/decision-notes project convention}
        {--local-agent-notes : Include AGENTS.local.example.md and initialize ignored AGENTS.local.md}
        {--agent-skills : Include tracked .ai/skills and ignored activation-folder convention}
        {--tools-path=tools/agent-ready : Relative path for generated Agent-Ready host scripts}
        {--docker-path=docker/agent-ready : Relative root path for generated Agent-Ready Docker support files}
        {--traefik-home= : Absolute host path to shared-traefik; persisted in ignored .agent-ready/config.json}
        {--services-home= : Absolute host path to shared-services; persisted in ignored .agent-ready/config.json}
        {--route-domain= : Main checkout route domain; defaults to the project slug plus .localhost}
        {--existing-project-candidates : Generate only safe existing-project companion candidate files}';

    protected $description = 'Install Agent-Ready Laravel local-development tooling';

    public function __construct(
        private readonly InstallPlanner $planner,
        private readonly InstallPlanApplier $installer,
        private readonly ExistingProjectDetector $existingProjects,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $context = ProjectContext::fromPath(base_path());

        try {
            $toolsPath = $this->relativePathOption('tools-path', 'tools/agent-ready');
            $dockerPath = $this->relativePathOption('docker-path', 'docker/agent-ready');
            $hostRoot = $this->hostRootOption();
            $traefikHome = $this->absolutePathOption('traefik-home');
            $servicesHome = $this->absolutePathOption('services-home');
            $routeDomain = $this->domainOption('route-domain', $context->defaultRouteDomain());
            $runtime = $this->choiceOption('runtime', InstallOptions::RUNTIMES, InstallOptions::DEFAULT_RUNTIME);
            $databaseEngine = $this->choiceOption('database', InstallOptions::DATABASE_ENGINES, InstallOptions::DEFAULT_DATABASE_ENGINE);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $decisionNotes = (bool) $this->option('decision-notes');
        $localAgentNotes = (bool) $this->option('local-agent-notes');
        $agentSkills = (bool) $this->option('agent-skills');
        $horizon = (bool) $this->option('horizon');
        $existingProjectCandidates = (bool) $this->option('existing-project-candidates');
        $hasDecisionNotes = $this->pathExists($context->root, AgentReadyPath::DECISION_NOTES_README);
        $hasLocalAgentNotes = $this->pathExists($context->root, AgentReadyPath::AGENTS_LOCAL)
            || $this->pathExists($context->root, AgentReadyPath::AGENTS_LOCAL_EXAMPLE);
        $hasAgentSkills = is_dir($context->root.'/'.AgentReadyPath::AI_SKILLS)
            || $this->pathExists($context->root, AgentReadyPath::AGENTS_SKILLS);
        $config = $this->agentReadyConfig($context->root);
        $configuredTraefikHome = $this->configString($config, 'traefikHome');
        $configuredServicesHome = $this->configString($config, 'servicesHome');

        if ($this->input->isInteractive()) {
            $promptForHostRoot = $hostRoot === null;
            $promptForToolsPath = ! $this->optionWasPassed('tools-path');
            $promptForDockerPath = ! $this->optionWasPassed('docker-path');
            $promptForRouteDomain = ! $this->optionWasPassed('route-domain');

            if ($promptForHostRoot || $promptForToolsPath || $promptForDockerPath || $promptForRouteDomain) {
                $this->components->info('Choose where Agent-Ready should store host-side paths and generated files.');
            }

            try {
                if ($promptForHostRoot) {
                    $this->line('Host project root is the real path to this app on your machine. Worktree commands store it for symlinks and cleanup, especially when installing from Docker.');
                    $hostRoot = PathInput::absoluteOption('host-root', (string) $this->ask('Host project root', $context->root));
                }

                if ($promptForToolsPath) {
                    $this->line('Host script path is where tracked shell helpers live. The ./local entrypoint delegates to these files.');
                    $toolsPath = PathInput::relativeOption('tools-path', (string) $this->ask('Host script path', $toolsPath));
                }

                if ($promptForDockerPath) {
                    $this->line('Docker support root is where tracked Docker support files live');
                    $dockerPath = PathInput::relativeOption('docker-path', (string) $this->ask('Docker support root path', $dockerPath));
                }

                if ($promptForRouteDomain) {
                    $this->line('Route domain is the main checkout host and worktree namespace, for example nexus.localhost. Worktrees use <id>.'.$routeDomain.'.');
                    $routeDomain = PathInput::domainOption('route-domain', (string) $this->ask('Route domain', $routeDomain));
                }
            } catch (InvalidArgumentException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }
        } elseif ($hostRoot === null) {
            $this->components->error('Option --host-root is required when running non-interactively.');

            return self::FAILURE;
        }

        $existingProject = $this->existingProjects->detect($context, $dockerPath);
        $preflightOptions = new InstallOptions(
            hostRoot: $hostRoot,
            decisionNotes: $decisionNotes,
            localAgentNotes: $localAgentNotes,
            agentSkills: $agentSkills,
            toolsPath: $toolsPath,
            dockerPath: $dockerPath,
            routeDomain: $routeDomain,
            runtime: $runtime,
            databaseEngine: $databaseEngine,
            horizon: $horizon,
        );

        if ($existingProjectCandidates) {
            return $this->writeExistingProjectCandidates($context, $existingProject, new InstallOptions(
                hostRoot: $hostRoot,
                decisionNotes: $decisionNotes,
                localAgentNotes: $localAgentNotes,
                agentSkills: $agentSkills,
                toolsPath: $toolsPath,
                dockerPath: $dockerPath,
                traefikHome: $traefikHome ?? $configuredTraefikHome,
                servicesHome: $servicesHome ?? $configuredServicesHome,
                routeDomain: $routeDomain,
                runtime: $runtime,
                databaseEngine: $databaseEngine,
                horizon: $horizon,
            ));
        }

        if ($existingProject->shouldStopFreshInstall() && ! $this->existingProjectFactsAreSafeGeneratedRerun($context, $existingProject, $preflightOptions)) {
            $this->reportExistingProjectStop($existingProject);

            return self::FAILURE;
        }

        if ($this->input->isInteractive()) {
            $promptForTraefikHome = $traefikHome === null && $configuredTraefikHome === null;
            $promptForServicesHome = $servicesHome === null && $configuredServicesHome === null;

            try {

                if ($promptForTraefikHome) {
                    $this->line('Shared Traefik is required for routed app and Vite access. Pass its path, or keep shared-traefik next to this project so Agent-Ready can discover it.');
                    $detected = $this->detectedTraefikHome($hostRoot);
                    $answer = (string) $this->ask('Shared Traefik path', $detected ?? '');
                    if ($answer === '' && $detected === null) {
                        throw new InvalidArgumentException('Shared Traefik is required. Pass --traefik-home=/path/to/shared-traefik or keep shared-traefik next to this project.');
                    }

                    $traefikHome = $answer !== '' ? PathInput::absoluteOption('traefik-home', $answer) : $detected;
                }

                if ($promptForServicesHome) {
                    $this->line('Shared services are required for Agent-Ready-managed databases and Valkey. Pass its path, or keep shared-services next to this project so Agent-Ready can discover it.');
                    $detected = $this->detectedServicesHome($hostRoot);
                    $answer = (string) $this->ask('Shared services path', $detected ?? '');
                    if ($answer === '' && $detected === null) {
                        throw new InvalidArgumentException('Shared services are required. Pass --services-home=/path/to/shared-services or keep shared-services next to this project.');
                    }

                    $servicesHome = $answer !== '' ? PathInput::absoluteOption('services-home', $answer) : $detected;
                }
            } catch (InvalidArgumentException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }

            if (! $localAgentNotes && ! $hasLocalAgentNotes) {
                $this->line('Local agent notes add a tracked AGENTS.local.example.md and ignored AGENTS.local.md for machine-specific notes, secrets references, and personal agent preferences.');
                $localAgentNotes = $this->confirm('Add local agent notes convention?');
            }

            if (! $agentSkills && ! $hasAgentSkills) {
                $this->line('Agent skills add tracked .ai/skills source files and an ignored .agents/skills activation folder for tools that support skills.');
                $agentSkills = $this->confirm('Add agent skills convention?');
            }

            if (! $decisionNotes && ! $hasDecisionNotes) {
                $this->line('Decision notes add an optional docs/decision-notes convention for recording project decisions.');
                $decisionNotes = $this->confirm('Add decision-notes guidance?');
            }
        }

        $detectedTraefikHome = $this->detectedTraefikHome($hostRoot);
        $detectedServicesHome = $this->detectedServicesHome($hostRoot);
        $resolvedTraefikHome = $traefikHome ?? $configuredTraefikHome ?? $detectedTraefikHome;
        $resolvedServicesHome = $servicesHome ?? $configuredServicesHome ?? $detectedServicesHome;

        if ($resolvedTraefikHome === null) {
            $this->components->error('Shared Traefik is required. Pass --traefik-home=/path/to/shared-traefik or keep shared-traefik next to this project.');

            return self::FAILURE;
        }

        if ($resolvedServicesHome === null) {
            $this->components->error('Shared services are required. Pass --services-home=/path/to/shared-services or keep shared-services next to this project.');

            return self::FAILURE;
        }

        $options = new InstallOptions(
            hostRoot: $hostRoot,
            decisionNotes: $decisionNotes,
            localAgentNotes: $localAgentNotes,
            agentSkills: $agentSkills,
            toolsPath: $toolsPath,
            dockerPath: $dockerPath,
            traefikHome: $resolvedTraefikHome,
            servicesHome: $resolvedServicesHome,
            routeDomain: $routeDomain,
            runtime: $runtime,
            databaseEngine: $databaseEngine,
            horizon: $horizon,
        );
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
            $this->components->error('Installation stopped because existing files would be overwritten.');

            foreach ($this->conflictGuidance($context->root, $result->conflictPaths, $toolsPath, $dockerPath) as $message) {
                $this->components->info($message);
            }

            return self::FAILURE;
        }

        try {
            $configUpdated = $this->syncLocalConfig($context->root, $resolvedTraefikHome, $resolvedServicesHome);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

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

        if ($configUpdated && ! in_array(AgentReadyPath::AGENT_READY_CONFIG, $result->initializedPaths, true)) {
            $this->line('Updated '.AgentReadyPath::AGENT_READY_CONFIG);
        }

        if (in_array(AgentReadyPath::ENV_TESTING_EXAMPLE, $result->initializedPaths, true)) {
            $this->components->warn('Review .env.testing.example before committing; it was generated from local env files and may contain secrets.');
        }

        if ($result->writtenPaths === []) {
            $this->components->info('All generated files were already up to date.');
        }

        if (! $options->decisionNotes && ! $hasDecisionNotes) {
            $this->components->info('Optional: rerun with --decision-notes to add lightweight project decision-note guidance.');
        }

        if ($traefikHome === null && $configuredTraefikHome === null && $detectedTraefikHome !== null) {
            $this->components->info("Using discovered shared Traefik at {$detectedTraefikHome}.");
        }

        if ($servicesHome === null && $configuredServicesHome === null && $detectedServicesHome !== null) {
            $this->components->info("Using discovered shared services at {$detectedServicesHome}.");
        }

        $this->components->info('Inspect tracked changes with git diff, then run ./local up. Local state lives in ignored .agent-ready/.');

        return self::SUCCESS;
    }

    private function writeExistingProjectCandidates(ProjectContext $context, ExistingProjectFacts $facts, InstallOptions $options): int
    {
        if ($facts->hasAnyFacts()) {
            $this->components->info('Detected existing project facts:');
            $this->writeExistingProjectFacts($facts);
        }

        try {
            $plan = $this->planner->existingProjectCandidatesPlan($context, $options);
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
            $this->components->error('Existing project candidate generation stopped because existing files would be overwritten.');
            $this->components->info('Agent-Ready did not modify any files.');

            if (in_array(AgentReadyPath::WORKTREE_COMPOSE, $result->conflictPaths, true)) {
                $this->components->info('Existing worktree.docker-compose.yml differs from the Agent-Ready candidate. Review, move, or remove it before rerunning.');
            }

            return self::FAILURE;
        }

        foreach ($result->writtenPaths as $path) {
            $this->line("Wrote {$path}");
        }

        if ($result->writtenPaths === []) {
            $this->components->info('Existing project candidate files were already up to date.');
        }

        $this->components->info('Only worktree.docker-compose.yml was considered. User-owned files were left unchanged, and the candidate is not wired automatically.');

        return self::SUCCESS;
    }

    private function reportExistingProjectStop(ExistingProjectFacts $facts): void
    {
        $this->components->warn('Existing project stack or user-owned workflow files detected.');
        $this->writeExistingProjectFacts($facts);
        $this->components->info('Agent-Ready stopped before writing generated fresh-install files. User-owned files were left unchanged.');
        $this->components->info('Rerun with --existing-project-candidates to write only worktree.docker-compose.yml for review and manual integration.');
    }

    private function writeExistingProjectFacts(ExistingProjectFacts $facts): void
    {
        foreach ($facts->summaryLines() as $line) {
            $this->line(' - '.$line);
        }
    }

    private function existingProjectFactsAreSafeGeneratedRerun(ProjectContext $context, ExistingProjectFacts $facts, InstallOptions $options): bool
    {
        if ($facts->sailIndicators !== []) {
            return false;
        }

        try {
            $plan = $this->planner->plan($context, $options);
        } catch (InvalidArgumentException) {
            return false;
        }

        if ($this->installer->conflictPaths($context->root, $plan) !== []) {
            return false;
        }

        if (! $this->allConflictManagedPlanFilesMatch($context->root, $plan)) {
            return false;
        }

        foreach ($facts->composeFiles as $path) {
            if (! $this->hasGeneratedPlanFile($plan, $path)) {
                return false;
            }
        }

        if ($facts->hasLocalEntrypoint && ! $this->hasGeneratedPlanFile($plan, AgentReadyPath::LOCAL)) {
            return false;
        }

        foreach ($facts->dockerSupportPaths as $path) {
            if (! $this->dockerSupportPathMatchesGeneratedPlan($context->root, $path, $plan)) {
                return false;
            }
        }

        return true;
    }

    private function allConflictManagedPlanFilesMatch(string $root, InstallationPlan $plan): bool
    {
        foreach ($plan->operations() as $operation) {
            if ($operation instanceof WriteFileOperation && $operation->conflictsIfDifferent() && ! $this->fileMatchesGeneratedOperation($root, $operation)) {
                return false;
            }
        }

        return true;
    }

    private function dockerSupportPathMatchesGeneratedPlan(string $root, string $path, InstallationPlan $plan): bool
    {
        if (! str_ends_with($path, '/')) {
            return $this->hasGeneratedPlanFile($plan, $path);
        }

        $directory = rtrim($path, '/');
        $operations = $this->generatedPlanFilesUnder($plan, $directory);

        if ($operations === []) {
            return false;
        }

        foreach ($operations as $operation) {
            if (! $this->fileMatchesGeneratedOperation($root, $operation)) {
                return false;
            }
        }

        foreach ($this->relativeFilesUnder($root, $directory) as $existingFile) {
            if (! array_key_exists($existingFile, $operations)) {
                return false;
            }
        }

        return true;
    }

    private function hasGeneratedPlanFile(InstallationPlan $plan, string $path): bool
    {
        try {
            $operation = $plan->operationFor($path);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $operation instanceof WriteFileOperation && $operation->conflictsIfDifferent();
    }

    /**
     * @return array<string, WriteFileOperation>
     */
    private function generatedPlanFilesUnder(InstallationPlan $plan, string $directory): array
    {
        $prefix = rtrim($directory, '/').'/';
        $operations = [];

        foreach ($plan->operations() as $operation) {
            if ($operation instanceof WriteFileOperation && $operation->conflictsIfDifferent() && str_starts_with($operation->path, $prefix)) {
                $operations[$operation->path] = $operation;
            }
        }

        return $operations;
    }

    private function fileMatchesGeneratedOperation(string $root, WriteFileOperation $operation): bool
    {
        $target = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $operation->path);

        return is_file($target) && (string) file_get_contents($target) === $operation->content;
    }

    /**
     * @return array<int, string>
     */
    private function relativeFilesUnder(string $root, string $directory): array
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $target = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, trim($directory, '/'));

        if (! is_dir($target)) {
            return [];
        }

        $paths = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || (! $file->isFile() && ! $file->isLink())) {
                continue;
            }

            $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        }

        sort($paths);

        return $paths;
    }

    private function hostRootOption(): ?string
    {
        return $this->absolutePathOption('host-root');
    }

    private function absolutePathOption(string $name): ?string
    {
        if (! $this->optionWasPassed($name)) {
            return null;
        }

        $option = $this->option($name);

        return PathInput::absoluteOption($name, is_string($option) ? $option : '');
    }

    private function relativePathOption(string $name, string $default): string
    {
        if (! $this->optionWasPassed($name)) {
            return PathInput::relativeOption($name, $default);
        }

        $value = $this->option($name);

        return PathInput::relativeOption($name, is_string($value) ? $value : '');
    }

    private function domainOption(string $name, string $default): string
    {
        if (! $this->optionWasPassed($name)) {
            return PathInput::domainOption($name, $default);
        }

        $option = $this->option($name);

        return PathInput::domainOption($name, is_string($option) ? $option : '');
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function choiceOption(string $name, array $allowed, string $default): string
    {
        if (! $this->optionWasPassed($name)) {
            return $default;
        }

        $option = $this->option($name);
        $value = is_string($option) ? $option : '';

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Option --'.$name.' must be one of: '.implode(', ', $allowed).'.');
        }

        return $value;
    }

    private function optionWasPassed(string $name): bool
    {
        return $this->input->hasParameterOption('--'.$name);
    }

    private function detectedTraefikHome(string $root): ?string
    {
        $sibling = dirname($root).'/shared-traefik';

        return is_executable($sibling.'/bin/ensure') ? $sibling : null;
    }

    private function detectedServicesHome(string $root): ?string
    {
        $sibling = dirname($root).'/shared-services';

        return is_executable($sibling.'/bin/ensure') ? $sibling : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function agentReadyConfig(string $root): array
    {
        $path = $root.'/'.AgentReadyPath::AGENT_READY_CONFIG;

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function configString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function syncLocalConfig(string $root, string $traefikHome, string $servicesHome): bool
    {
        $path = $root.'/'.AgentReadyPath::AGENT_READY_CONFIG;
        $config = $this->agentReadyConfig($root);
        $config['version'] = 1;
        $config['traefikHome'] = $traefikHome;
        $config['servicesHome'] = $servicesHome;
        $content = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (is_file($path) && (string) file_get_contents($path) === $content) {
            return false;
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write ['.AgentReadyPath::AGENT_READY_CONFIG.'].');
        }

        return true;
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function conflictGuidance(string $root, array $paths, string $toolsPath, string $dockerPath): array
    {
        $messages = ['Agent-Ready did not modify any files.'];

        if (in_array(AgentReadyPath::LOCAL, $paths, true)) {
            $messages[] = 'Existing ./local detected. Agent-Ready expects ./local to be its entrypoint; rename or adapt the existing script before rerunning if you want Agent-Ready to own it.';
        }

        if ($this->hasComposeConflict($paths)) {
            $messages[] = $this->looksLikeSailCompose($root)
                ? 'Existing Laravel Sail Compose detected. Agent-Ready does not require Sail and will not rewrite Sail files; decide whether to keep Sail or migrate before rerunning.'
                : 'Existing Compose files detected. Agent-Ready V1 does not merge Compose files; keep them user-owned and compare a clean throwaway install before manual adoption.';
        }

        if ($this->hasPathUnder($paths, $toolsPath)) {
            $messages[] = "Existing files under {$toolsPath}/ detected. Rerun with --tools-path=... if that tree is already used by this project.";
        }

        if ($this->hasPathUnder($paths, $dockerPath)) {
            $messages[] = "Existing files under {$dockerPath}/ detected. Rerun with --docker-path=... if that tree is already used by this project.";
        }

        if (in_array(AgentReadyPath::OPENCODE_EXAMPLE, $paths, true)) {
            $messages[] = 'Existing opencode.jsonc.example detected. Compare it manually with Agent-Ready guidance before choosing which version to keep.';
        }

        return array_values(array_unique($messages));
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function hasComposeConflict(array $paths): bool
    {
        return in_array(AgentReadyPath::COMPOSE, $paths, true);
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function hasPathUnder(array $paths, string $directory): bool
    {
        $prefix = rtrim($directory, '/').'/';

        foreach ($paths as $path) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeSailCompose(string $root): bool
    {
        $compose = $root.'/'.AgentReadyPath::COMPOSE;

        if (! is_file($compose)) {
            return false;
        }

        $content = file_get_contents($compose);

        if (! is_string($content)) {
            return false;
        }

        return str_contains($content, 'laravel.test')
            || str_contains($content, 'vendor/laravel/sail')
            || str_contains(strtolower($content), 'laravel sail');
    }

    private function pathExists(string $root, string $path): bool
    {
        $target = $root.'/'.$path;

        return file_exists($target) || is_link($target);
    }
}
