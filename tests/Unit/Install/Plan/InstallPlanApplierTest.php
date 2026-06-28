<?php

declare(strict_types=1);

namespace AgentsReady\LaravelInstaller\Tests\Unit\Install\Plan;

use AgentsReady\LaravelInstaller\Install\Operations\AppendMissingLinesOperation;
use AgentsReady\LaravelInstaller\Install\Operations\UpsertMarkedBlockOperation;
use AgentsReady\LaravelInstaller\Install\Operations\WriteFileOperation;
use AgentsReady\LaravelInstaller\Install\Plan\InstallationPlan;
use AgentsReady\LaravelInstaller\Install\Plan\InstallPlanApplier;
use AgentsReady\LaravelInstaller\Install\Support\TextFilePatcher;
use AgentsReady\LaravelInstaller\Tests\UnitTestCase;

final class InstallPlanApplierTest extends UnitTestCase
{
    public function test_existing_different_generated_files_are_refused_before_any_operations_apply(): void
    {
        $root = $this->makeProjectRoot();
        file_put_contents($root.'/docker-compose.yml', "services:\n  app:\n    image: custom\n");

        $plan = new InstallationPlan([
            WriteFileOperation::conflictIfDifferent('local', "#!/usr/bin/env bash\n"),
            WriteFileOperation::conflictIfDifferent('docker-compose.yml', "services: {}\n"),
            WriteFileOperation::createIfMissing('.agents-ready/worktree.json', "{}\n"),
            new UpsertMarkedBlockOperation('AGENTS.md', '<!-- start -->', '<!-- end -->', 'Managed'),
        ]);

        $result = (new InstallPlanApplier(new TextFilePatcher))->apply($root, $plan);

        $this->assertFalse($result->successful());
        $this->assertSame(['docker-compose.yml'], $result->conflictPaths);
        $this->assertSame([], $result->writtenPaths);
        $this->assertSame([], $result->initializedPaths);
        $this->assertSame([], $result->updatedPaths);
        $this->assertFileDoesNotExist($root.'/local');
        $this->assertFileDoesNotExist($root.'/.agents-ready/worktree.json');
        $this->assertFileDoesNotExist($root.'/AGENTS.md');
        $this->assertSame("services:\n  app:\n    image: custom\n", file_get_contents($root.'/docker-compose.yml'));
    }

    public function test_incomplete_marked_blocks_are_refused_before_any_operations_apply(): void
    {
        $root = $this->makeProjectRoot();
        file_put_contents($root.'/AGENTS.md', "Before\n<!-- start -->\nOld\n");

        $plan = new InstallationPlan([
            WriteFileOperation::conflictIfDifferent('local', "#!/usr/bin/env bash\n"),
            WriteFileOperation::createIfMissing('.agents-ready/worktree.json', "{}\n"),
            new UpsertMarkedBlockOperation('AGENTS.md', '<!-- start -->', '<!-- end -->', 'Managed'),
        ]);

        $result = (new InstallPlanApplier(new TextFilePatcher))->apply($root, $plan);

        $this->assertFalse($result->successful());
        $this->assertSame(['AGENTS.md'], $result->conflictPaths);
        $this->assertSame([], $result->writtenPaths);
        $this->assertSame([], $result->initializedPaths);
        $this->assertSame([], $result->updatedPaths);
        $this->assertFileDoesNotExist($root.'/local');
        $this->assertFileDoesNotExist($root.'/.agents-ready/worktree.json');
        $this->assertSame("Before\n<!-- start -->\nOld\n", file_get_contents($root.'/AGENTS.md'));
    }

    public function test_identical_generated_files_make_apply_idempotent(): void
    {
        $root = $this->makeProjectRoot();
        file_put_contents($root.'/local', "#!/usr/bin/env bash\n");
        chmod($root.'/local', 0755);
        file_put_contents($root.'/docker-compose.yml', "services: {}\n");

        $plan = new InstallationPlan([
            WriteFileOperation::conflictIfDifferent('local', "#!/usr/bin/env bash\n", 0755),
            WriteFileOperation::conflictIfDifferent('docker-compose.yml', "services: {}\n"),
        ]);

        $result = (new InstallPlanApplier(new TextFilePatcher))->apply($root, $plan);

        $this->assertTrue($result->successful());
        $this->assertSame([], $result->conflictPaths);
        $this->assertSame([], $result->writtenPaths);
    }

    public function test_identical_generated_files_with_wrong_mode_are_repaired(): void
    {
        $root = $this->makeProjectRoot();
        file_put_contents($root.'/local', "#!/usr/bin/env bash\n");
        chmod($root.'/local', 0644);

        $plan = new InstallationPlan([
            WriteFileOperation::conflictIfDifferent('local', "#!/usr/bin/env bash\n", 0755),
        ]);

        $result = (new InstallPlanApplier(new TextFilePatcher))->apply($root, $plan);

        $this->assertTrue($result->successful());
        $this->assertSame(['local'], $result->writtenPaths);
        $this->assertSame(0755, fileperms($root.'/local') & 0777);
    }

    public function test_apply_tracks_written_initialized_and_updated_paths_separately(): void
    {
        $root = $this->makeProjectRoot();
        file_put_contents($root.'/.gitignore', "/vendor\n");

        $plan = new InstallationPlan([
            WriteFileOperation::conflictIfDifferent('local', "#!/usr/bin/env bash\n", 0755),
            WriteFileOperation::createIfMissing('.agents-ready/worktree.json', "{}\n"),
            new UpsertMarkedBlockOperation('AGENTS.md', '<!-- start -->', '<!-- end -->', 'Managed'),
            new AppendMissingLinesOperation('.gitignore', ['/vendor', '/.agents-ready/']),
        ]);

        $result = (new InstallPlanApplier(new TextFilePatcher))->apply($root, $plan);

        $this->assertTrue($result->successful());
        $this->assertSame(['local'], $result->writtenPaths);
        $this->assertSame(['.agents-ready/worktree.json'], $result->initializedPaths);
        $this->assertSame(['AGENTS.md', '.gitignore'], $result->updatedPaths);
        $this->assertFileExists($root.'/local');
        $this->assertFileExists($root.'/.agents-ready/worktree.json');
        $this->assertStringContainsString('Managed', (string) file_get_contents($root.'/AGENTS.md'));
        $this->assertStringContainsString('/.agents-ready/', (string) file_get_contents($root.'/.gitignore'));
    }

    public function test_create_if_missing_file_operations_preserve_existing_local_state(): void
    {
        $root = $this->makeProjectRoot();
        file_put_contents($root.'/state.json', "custom\n");

        $plan = new InstallationPlan([
            WriteFileOperation::createIfMissing('state.json', "generated\n"),
        ]);

        $result = (new InstallPlanApplier(new TextFilePatcher))->apply($root, $plan);

        $this->assertTrue($result->successful());
        $this->assertSame([], $result->initializedPaths);
        $this->assertSame("custom\n", file_get_contents($root.'/state.json'));
    }

    private function makeProjectRoot(): string
    {
        return $this->temporaryDirectory('agents-ready-installer-test-');
    }
}
