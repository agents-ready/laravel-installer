<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Console;

use AgentsReady\LaravelInstaller\Install\AgentsReadyPath;
use AgentsReady\LaravelInstaller\Install\Detection\ExistingProjectDetector;
use AgentsReady\LaravelInstaller\Install\Detection\ExistingProjectFacts;
use AgentsReady\LaravelInstaller\Install\InstallOptions;
use AgentsReady\LaravelInstaller\Install\InstallPlanner;
use AgentsReady\LaravelInstaller\Install\Operations\WriteFileOperation;
use AgentsReady\LaravelInstaller\Install\Plan\InstallationPlan;
use AgentsReady\LaravelInstaller\Install\Plan\InstallPlanApplier;
use AgentsReady\LaravelInstaller\Install\ProjectContext;
use AgentsReady\LaravelInstaller\Install\Support\PathInput;
use FilesystemIterator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class InstallCommand extends Command
{
    protected $signature = 'agents-ready:install
        {--host-root= : Absolute host path to persist for managed worktree state when running inside Docker}
        {--runtime=frankenphp : Runtime to generate: frankenphp or nginx-php-fpm}
        {--database=pgsql : Database engine to use: pgsql or mysql}
        {--horizon : Include an optional Horizon worker service}
        {--tools-path=tools/agents-ready : Relative path for generated Agents-Ready host scripts}
        {--docker-path=docker/agents-ready : Relative root path for generated Agents-Ready Docker support files}
        {--traefik-home= : Absolute host path to shared-traefik; persisted in ignored .agents-ready/config.json}
        {--services-home= : Absolute host path to shared-services; persisted in ignored .agents-ready/config.json}
        {--project-slug= : Project slug for local resource identity; defaults from composer.json or host root}
        {--route-domain= : Main checkout route domain; defaults to the project slug plus .localhost}
        {--existing-project-candidates : Generate only safe existing-project companion candidate files}';

    protected $description = 'Install Agents-Ready Laravel local-development tooling';

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
            $toolsPath = $this->relativePathOption('tools-path', 'tools/agents-ready');
            $dockerPath = $this->relativePathOption('docker-path', 'docker/agents-ready');
            $hostRoot = $this->hostRootOption();
            $traefikHome = $this->absolutePathOption('traefik-home');
            $servicesHome = $this->absolutePathOption('services-home');
            $projectSlug = $this->projectSlugOption();
            $routeDomain = $this->routeDomainOption();
            $runtime = $this->choiceOption('runtime', InstallOptions::RUNTIMES);
            $databaseEngine = $this->choiceOption('database', InstallOptions::DATABASE_ENGINES);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($hostRoot === null) {
            $this->components->error('Option --host-root is required. Pass the real host checkout path, especially when installing from Docker.');

            return self::FAILURE;
        }

        if ($traefikHome === null) {
            $this->components->error('Option --traefik-home is required. Pass the absolute path to shared-traefik.');

            return self::FAILURE;
        }

        if ($servicesHome === null) {
            $this->components->error('Option --services-home is required. Pass the absolute path to shared-services.');

            return self::FAILURE;
        }

        $horizon = (bool) $this->option('horizon');
        $existingProjectCandidates = (bool) $this->option('existing-project-candidates');
        if ($this->input->isInteractive()) {
            $promptForToolsPath = ! $this->optionWasPassed('tools-path');
            $promptForDockerPath = ! $this->optionWasPassed('docker-path');
            $promptForProjectSlug = $projectSlug === null;
            $promptForRuntime = $runtime === null;
            $promptForDatabase = $databaseEngine === null;

            if ($promptForToolsPath || $promptForDockerPath || $promptForProjectSlug || $promptForRuntime || $promptForDatabase) {
                $this->components->info('Choose where Agents-Ready should store host-side paths and generated files.');
            }

            try {
                if ($promptForToolsPath) {
                    $this->line('Host script path is where tracked shell helpers live. The ./local entrypoint delegates to these files.');
                    $toolsPath = PathInput::relativeOption('tools-path', (string) $this->ask('Host script path', $toolsPath));
                }

                if ($promptForDockerPath) {
                    $this->line('Docker support root is where tracked Docker support files live');
                    $dockerPath = PathInput::relativeOption('docker-path', (string) $this->ask('Docker support root path', $dockerPath));
                }

                if ($promptForProjectSlug) {
                    $defaultProjectSlug = $context->slug($hostRoot);
                    $this->line('Project slug identifies local resources and defaults the main route to <project-slug>.localhost.');
                    $projectSlug = PathInput::projectSlugOption('project-slug', (string) $this->ask('Project slug', $defaultProjectSlug));
                }

                if ($promptForRuntime) {
                    $runtime = (string) $this->choice('Runtime', InstallOptions::RUNTIMES, InstallOptions::DEFAULT_RUNTIME);
                }

                if ($promptForDatabase) {
                    $databaseEngine = (string) $this->choice('Database', InstallOptions::DATABASE_ENGINES, InstallOptions::DEFAULT_DATABASE_ENGINE);
                }
            } catch (InvalidArgumentException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $projectSlug ??= $context->slug($hostRoot);
        $routeDomain ??= $projectSlug.'.localhost';
        $runtime ??= InstallOptions::DEFAULT_RUNTIME;
        $databaseEngine ??= InstallOptions::DEFAULT_DATABASE_ENGINE;

        $existingProject = $this->existingProjects->detect($context, $dockerPath);
        $preflightOptions = new InstallOptions(
            hostRoot: $hostRoot,
            toolsPath: $toolsPath,
            dockerPath: $dockerPath,
            projectSlug: $projectSlug,
            routeDomain: $routeDomain,
            runtime: $runtime,
            databaseEngine: $databaseEngine,
            horizon: $horizon,
        );

        if ($existingProjectCandidates) {
            return $this->writeExistingProjectCandidates($context, $existingProject, new InstallOptions(
                hostRoot: $hostRoot,
                toolsPath: $toolsPath,
                dockerPath: $dockerPath,
                traefikHome: $traefikHome,
                servicesHome: $servicesHome,
                projectSlug: $projectSlug,
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

        $options = new InstallOptions(
            hostRoot: $hostRoot,
            toolsPath: $toolsPath,
            dockerPath: $dockerPath,
            traefikHome: $traefikHome,
            servicesHome: $servicesHome,
            projectSlug: $projectSlug,
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
            $configUpdated = $this->syncLocalConfig($context->root, $toolsPath, $dockerPath, $traefikHome, $servicesHome);
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

        if ($configUpdated && ! in_array(AgentsReadyPath::AGENTS_READY_CONFIG, $result->initializedPaths, true)) {
            $this->line('Updated '.AgentsReadyPath::AGENTS_READY_CONFIG);
        }

        if (in_array(AgentsReadyPath::ENV_TESTING_EXAMPLE, $result->initializedPaths, true)) {
            $this->components->warn('Review .env.testing.example before committing; it was generated from local env files and may contain secrets.');
        }

        if ($result->writtenPaths === []) {
            $this->components->info('All generated files were already up to date.');
        }

        $this->components->info('Optional: run php artisan agents-ready:conventions to add project/team agent conventions.');

        $this->components->info('Inspect tracked changes with git diff, then run ./local up. Local state lives in ignored .agents-ready/.');

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
            $this->components->info('Agents-Ready did not modify any files.');

            if (in_array(AgentsReadyPath::WORKTREE_COMPOSE, $result->conflictPaths, true)) {
                $this->components->info('Existing worktree.docker-compose.yml differs from the Agents-Ready candidate. Review, move, or remove it before rerunning.');
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
        $this->components->info('Agents-Ready stopped before writing generated fresh-install files. User-owned files were left unchanged.');
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

        if ($facts->hasLocalEntrypoint && ! $this->hasGeneratedPlanFile($plan, AgentsReadyPath::LOCAL)) {
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

    private function projectSlugOption(): ?string
    {
        if (! $this->optionWasPassed('project-slug')) {
            return null;
        }

        $option = $this->option('project-slug');

        return PathInput::projectSlugOption('project-slug', is_string($option) ? $option : '');
    }

    private function routeDomainOption(): ?string
    {
        if (! $this->optionWasPassed('route-domain')) {
            return null;
        }

        $option = $this->option('route-domain');

        return PathInput::domainOption('route-domain', is_string($option) ? $option : '');
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function choiceOption(string $name, array $allowed): ?string
    {
        if (! $this->optionWasPassed($name)) {
            return null;
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

    private function syncLocalConfig(string $root, string $toolsPath, string $dockerPath, string $traefikHome, string $servicesHome): bool
    {
        $path = $root.'/'.AgentsReadyPath::AGENTS_READY_CONFIG;
        $config = $this->agentsReadyConfig($root);
        $config['version'] = 1;
        $config['toolsPath'] = $toolsPath;
        $config['dockerPath'] = $dockerPath;
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
            throw new RuntimeException('Unable to write ['.AgentsReadyPath::AGENTS_READY_CONFIG.'].');
        }

        return true;
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function conflictGuidance(string $root, array $paths, string $toolsPath, string $dockerPath): array
    {
        $messages = ['Agents-Ready did not modify any files.'];

        if (in_array(AgentsReadyPath::LOCAL, $paths, true)) {
            $messages[] = 'Existing ./local detected. Agents-Ready expects ./local to be its entrypoint; rename or adapt the existing script before rerunning if you want Agents-Ready to own it.';
        }

        if ($this->hasComposeConflict($paths)) {
            $messages[] = $this->looksLikeSailCompose($root)
                ? 'Existing Laravel Sail Compose detected. Agents-Ready does not require Sail and will not rewrite Sail files; decide whether to keep Sail or migrate before rerunning.'
                : 'Existing Compose files detected. Agents-Ready V1 does not merge Compose files; keep them user-owned and compare a clean throwaway install before manual adoption.';
        }

        if ($this->hasPathUnder($paths, $toolsPath)) {
            $messages[] = "Existing files under {$toolsPath}/ detected. Rerun with --tools-path=... if that tree is already used by this project.";
        }

        if ($this->hasPathUnder($paths, $dockerPath)) {
            $messages[] = "Existing files under {$dockerPath}/ detected. Rerun with --docker-path=... if that tree is already used by this project.";
        }

        if (in_array(AgentsReadyPath::OPENCODE_EXAMPLE, $paths, true)) {
            $messages[] = 'Existing opencode.jsonc.example detected. Compare it manually with Agents-Ready guidance before choosing which version to keep.';
        }

        return array_values(array_unique($messages));
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function hasComposeConflict(array $paths): bool
    {
        return in_array(AgentsReadyPath::COMPOSE, $paths, true);
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
        $compose = $root.'/'.AgentsReadyPath::COMPOSE;

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
}
